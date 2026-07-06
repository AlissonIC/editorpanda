# Limpeza de arquivos e garantia de não-órfãos

Sistema em 3 camadas que garante que nunca há arquivos "fantasma" no storage
(local ou S3) sem correspondência no banco de dados.

## Como funciona a garantia

### Camada 1: verificação redundante em CADA delete

Todo delete de arquivo passa por [`StorageCleanup::deleteAndVerify()`](../app/Support/StorageCleanup.php)
que faz:

```php
$storage->delete($path);
if ($storage->exists($path)) {
    // algo deu errado — registra em `arquivos_orfaos` para retry
    ArquivoOrfao::updateOrCreate(['disk' => $disk, 'path' => $path], [...]);
}
```

Isso vale para:
- **Vídeos** (`Video::deleting` → apaga original, processado, thumbnail)
- **Logos de eventos** (`Evento::deleting`, `EventosController::deleteLogo`)
- **Thumbnails** (substituição no upload de nova thumb)
- **Fotos de perfil** (`PerfilController::deleteFoto`/`updateFoto`)

Se qualquer delete falhar (rede, permissão, S3 down), a informação NÃO é perdida — vai para
a tabela `arquivos_orfaos` que é retentada periodicamente.

### Camada 2: tabela `arquivos_orfaos`

| Coluna | Uso |
|---|---|
| `disk` + `path` | UNIQUE — identifica o arquivo |
| `motivo` | Origem (video_delete, thumbnail_replace, evento_delete_logo, scan_reverso, etc.) |
| `tentativas` | Incrementa a cada retry falho |
| `ultimo_erro` | Última mensagem de erro (500 chars) |
| `ultima_tentativa_em` | Timestamp da última tentativa |

Órfãos com `tentativas >= 10` são flagados como "precisam de análise manual".

### Camada 3: comandos artisan agendados

Rodam diariamente / semanalmente e limpam TUDO:

| Comando | Frequência | Função |
|---|---|---|
| `panda:limpar-uploads-abandonados` | Diário 03:00 | Aborta multipart S3 e apaga temp local de vídeos travados em `status=enviando` há > 24h. Também detecta pastas `temp/videos/{N}` sem vídeo `#N` ativo. |
| `panda:limpar-orfaos` | Diário 03:15 | Retenta apagar cada linha de `arquivos_orfaos`. Sucesso → deleta a linha. Falha → incrementa `tentativas`. |
| `panda:scan-armazenamento` (local + public) | Semanal Domingo 04:00 | Percorre `videos/originais`, `videos/processados`, `thumbnails`, `logos-eventos`, `avatars` no storage e detecta arquivos SEM row no DB. Com `--apagar`, remove. |
| `panda:cleanup` | — | Pipeline master que chama os 3 acima em ordem. Útil pra rodar sob demanda. |

## Como executar

### Manualmente
```bash
# Pipeline completa em modo simulação
php artisan panda:cleanup --dry-run

# Só aborta uploads travados > 48h
php artisan panda:limpar-uploads-abandonados --horas=48

# Só retenta órfãos
php artisan panda:limpar-orfaos

# Scan reverso completo (inclui apagar)
php artisan panda:cleanup --scan --apagar
```

### Agendado (produção)

Adicionar ao crontab do servidor:
```cron
* * * * * cd /var/www/pandavideo && php artisan schedule:run >> /dev/null 2>&1
```

Isso dispara o Laravel Scheduler que já contém as regras em [`routes/console.php`](../routes/console.php):
- Uploads abandonados: diário 03:00
- Órfãos: diário 03:15
- Scan reverso: semanal (domingo 04:00 local, 04:15 public)

### Desenvolvimento
```bash
php artisan schedule:work
```
Mantém o scheduler rodando no foreground.

## Bônus recomendado: S3 Lifecycle Rule

Além do `panda:limpar-uploads-abandonados`, configure no bucket S3:

**AWS Console → S3 → seu bucket → Management → Lifecycle rules → Create rule**

```
Rule name: aborta-multipart-antigo
Prefix: (vazio — bucket todo)
Action: Delete incomplete multipart uploads
Days after initiation: 1
```

Isso é **defense-in-depth**: mesmo se nosso worker cair, o S3 cancela por conta própria.

Custo: zero. Benefício: nunca mais paga storage de multipart abandonado.

## Como verificar se está funcionando

```bash
# Órfãos pendentes de limpeza
php artisan tinker
> App\Models\ArquivoOrfao::count()
> App\Models\ArquivoOrfao::where('tentativas', '>=', 10)->get()

# Vídeos travados em "enviando"
> App\Models\Video::where('status', 'enviando')->count()

# Ver o que rodaria
php artisan panda:cleanup --scan --dry-run
```

## Troubleshooting

**"Muitos órfãos ficam com tentativas >= 10"**
Provável causa: permissão do bucket S3 ou credencial expirada. Investigar `ultimo_erro`:
```sql
SELECT disk, path, motivo, ultimo_erro FROM arquivos_orfaos WHERE tentativas >= 10;
```

**"Scan detecta arquivos legítimos como órfãos"**
Confira se o path do arquivo no DB **bate exatamente** com o path retornado pelo `allFiles()`.
Windows pode ter diferença de barra (`\` vs `/`) — Laravel normaliza mas vale conferir.

**"Comando roda mas nada é apagado"**
Adicione `-v` no fim pra ver logs detalhados. Rode com `--dry-run` primeiro pra confirmar o que seria removido.
