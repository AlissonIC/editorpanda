# Processamento de vídeos

Como funciona o pipeline que transforma o vídeo original em versão 9:16 (Instagram/Reels)
com **logo do evento** e **gradiente** opcional.

## Arquitetura

```
        Upload complete (VideosUploadController::complete)
                        │
                        ▼
                 Video.status = 'pendente'
                 ProcessarVideoJob enfileirado
                        │
                        ▼
                queue driver = database
                        │
                        ▼
              php artisan queue:work
                        │
                        ▼
              ProcessarVideoJob::handle()
                        │
                        ▼
                  VideoProcessor
       ┌────────────────┼────────────────┐
       ▼                ▼                ▼
  Storage::disk     ffprobe          ffmpeg
  (local/s3)                      (crop 9:16 + logo + gradiente)
       │                                 │
       │              ┌──────────────────┘
       ▼              ▼
    upload  ─── videos/processados/{user}/{uuid}.mp4
                        │
                        ▼
              status = 'concluido'
              arquivo_processado_path = ...
```

Tudo em PHP dentro da mesma stack — sem processo externo, sem outra linguagem.

## Pré-requisito único: FFmpeg no PATH

### Windows (XAMPP)
1. Baixa em https://www.gyan.dev/ffmpeg/builds/ o `ffmpeg-release-essentials.zip`
2. Extrai em `C:\ffmpeg`
3. Adiciona `C:\ffmpeg\bin` ao PATH do sistema (Painel de Controle → Variáveis de Ambiente)
4. Fecha e reabre o terminal e testa:
   ```powershell
   ffmpeg -version
   ffprobe -version
   ```

### Linux
```bash
sudo apt install -y ffmpeg
```

### macOS
```bash
brew install ffmpeg
```

### Caminho customizado (opcional)
Se preferir não mexer no PATH global, aponta no `.env`:
```env
FFMPEG_BIN=C:\ffmpeg\bin\ffmpeg.exe
FFPROBE_BIN=C:\ffmpeg\bin\ffprobe.exe
```

## Rodando o worker

O worker do Laravel é o próprio `queue:work`. Ele já lê da conexão `database`
(configurada em `QUEUE_CONNECTION=database` no `.env`).

**Um worker**:
```bash
php artisan queue:work --timeout=1830 --tries=1
```

**Dois workers em paralelo** (dobra throughput em máquinas com múltiplos cores):
```bash
# Terminal 1
php artisan queue:work --timeout=1830 --tries=1

# Terminal 2
php artisan queue:work --timeout=1830 --tries=1
```

Cada instância pega um job por vez. FFmpeg saturará 1-4 cores durante o encode.
Dimensione o número de workers baseado na sua CPU (regra prática: `nCores / 2`).

### Produção (Linux + systemd)

`/etc/systemd/system/pandavideo-worker@.service`:
```ini
[Unit]
Description=Panda Video Worker %i
After=mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/pandavideo
ExecStart=/usr/bin/php artisan queue:work --timeout=1830 --tries=1 --sleep=3
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now pandavideo-worker@1
sudo systemctl enable --now pandavideo-worker@2
```

### Produção (Windows)
Use **NSSM** (Non-Sucking Service Manager) para transformar o comando em serviço:
```powershell
nssm install PandaWorker "C:\xampp\php\php.exe" "C:\xampp\htdocs\pandavideo\artisan queue:work --timeout=1830 --tries=1"
```

## Configuração do processamento

Configurações ficam no **evento** (aba "Processamento" no modal de editar evento):

| Campo | Descrição |
|---|---|
| **Logo** | PNG/JPG/WEBP, até 2 MB. Salvo no disco vigente do sistema. |
| **Posição do logo** | 5 opções (cantos e centro). |
| **Escala do logo** | 5%–50% da largura do vídeo (default 15%). |
| **Gradiente** | Retângulo escuro 35% opaco na região do logo, para contraste. |
| **Centralizar rosto** | Placeholder — feature futura, requer mediapipe. |

Todos os vídeos de álbuns cujo evento tenha config → processados com aquela config.
Vídeos sem evento configurado → processados só com o crop 9:16 padrão.

## O pipeline em detalhe

Arquivo: [`app/Services/VideoProcessor.php`](../app/Services/VideoProcessor.php)

### 1. Download do original
Streamado do disk (`local` ou `s3`) para `storage/app/temp/processing-{id}/input.{ext}`.
`Storage::disk()->readStream()` funciona pros dois discos.

### 2. Download do logo (se houver)
Análogo, para o disco do evento.

### 3. `ffprobe` — descobre W×H
```bash
ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of json input.mp4
```

### 4. Decide o filtro base
- Paisagem/quadrado (`W ≥ H`): `scale=-2:1920,crop=1080:1920:(iw-1080)/2:0` (zoom-in centralizado)
- Retrato (`W < H`): `scale=1080:-2,crop=1080:1920:0:(ih-1920)/2`

### 5. Monta `filter_complex`
```
[0:v]<filtro_base>[v0]
[v0]drawbox=...black@0.35:t=fill[v1]  ← se gradiente
[1:v]scale=<logoW>:-1[logo]
[v1][logo]overlay=x=<pos>:y=<pos>[vout]  ← se logo
```

### 6. Executa
```bash
ffmpeg -y -i input.mp4 -i logo.png -filter_complex "..." \
       -map [vout] -map 0:a? \
       -c:v libx264 -preset medium -crf 22 -pix_fmt yuv420p -movflags +faststart \
       -c:a aac -b:a 128k -ar 48000 \
       -r 30 output.mp4
```

Output: **1080×1920, 30 fps, H.264 CRF 22, AAC 128k, MP4 faststart** (streaming-ready).

### 7. Upload
`Storage::disk($video->disk)->put('videos/processados/{user}/{uuid}.mp4', $stream)`.

### 8. Update DB
```php
$video->update([
    'arquivo_processado_path' => ...,
    'status' => 'concluido',
    'processado_em' => now(),
    'duracao_segundos' => ...,
]);
```

## Troubleshooting

### "ffmpeg: not found" ou "ffprobe: not found"
- Confirma no terminal: `ffmpeg -version`
- Se não achou: revê PATH ou seta `FFMPEG_BIN`/`FFPROBE_BIN` no `.env` com caminho absoluto

### Worker travado em "processando"
Se o worker crashar (força bruta, kill -9, tela azul), o vídeo fica travado no status.
Reset manual:
```sql
UPDATE videos SET status='pendente', erro_msg=NULL WHERE status='processando';
```

Ou pela tela `/painel/processamento` (admin) → botão "Reprocessar".

### "ffmpeg falhou: ..."
- `arquivo_original_path não encontrado`: o arquivo sumiu do disco. Reenviar.
- `codec not supported`: raro; alguns MP4s exóticos.
- Timeout: aumenta `--timeout` no `queue:work` para vídeos muito longos.

### Muitos vídeos falhando em série
Provavelmente FFmpeg com config errada. Testa manualmente:
```bash
ffmpeg -y -i storage/app/private/videos/originais/1/{uuid}.mp4 \
       -vf "scale=-2:1920,crop=1080:1920" \
       -c:v libx264 -crf 22 out.mp4
```

## Face-centering (feature futura)

O gancho está em `VideoProcessor::buildCommand()` → `$vFilter`. Para adicionar:

1. Instalar mediapipe (Python) e chamar via shell exec, OU
2. Usar detector do OpenCV via extensão PHP (`opencv-php`), OU
3. Chamar API externa (AWS Rekognition, Google Vision) por sample de frames

O output do detector é uma lista `[(timestamp, cx, cy)]`. Interpola pra função de tempo
e passa como offset dinâmico no filtro `crop=W:H:x=expr(dx):y=expr(dy)`.

Para MVP atual: crop estático centralizado é mais que suficiente.
