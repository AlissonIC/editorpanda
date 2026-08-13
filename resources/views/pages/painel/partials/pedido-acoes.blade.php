{{-- Botão de ação da linha do DataTable. Renderizado no servidor pra não
     montar HTML em string no JS. --}}
<a href="{{ route('painel.pedidos.show', $pedido) }}"
   class="btn btn-sm btn-outline-dark"
   title="Ver detalhes da compra">
    <i class="bi bi-receipt"></i>
</a>
