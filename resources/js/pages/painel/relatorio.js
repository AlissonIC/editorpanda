import axios from 'axios';
import Chart from 'chart.js/auto';

document.addEventListener('DOMContentLoaded', async () => {
    const [vendas, top] = await Promise.all([
        axios.get('/painel/relatorio/vendas-por-mes'),
        axios.get('/painel/relatorio/top-albuns'),
    ]);

    new Chart(document.getElementById('chart-vendas'), {
        type: 'line',
        data: {
            labels: vendas.data.labels,
            datasets: [{
                label: 'Vendas (R$)',
                data: vendas.data.totais,
                borderColor: '#7367f0',
                backgroundColor: 'rgba(115,103,240,0.1)',
                tension: 0.3,
                fill: true,
            }],
        },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } },
    });

    new Chart(document.getElementById('chart-top'), {
        type: 'doughnut',
        data: {
            labels: top.data.labels,
            datasets: [{
                data: top.data.totais,
                backgroundColor: ['#7367f0', '#00cfe8', '#28c76f', '#ff9f43', '#ea5455'],
            }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });
});
