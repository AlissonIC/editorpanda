/**
 * Concorrência adaptativa: começa em `start`, mede vazão por parte e cresce
 * enquanto o throughput agregado sobe, encolhe quando congestiona.
 *
 * Uso:
 *   const ac = new AdaptiveConcurrency({ min: 2, max: 6, start: 3 });
 *   ac.value                    // valor atual
 *   ac.recordPart(bytes, ms)    // registra parte terminada
 *   ac.recordFailure()          // uma falha → recua
 */
export class AdaptiveConcurrency {
    constructor({ min = 2, max = 6, start = 3 } = {}) {
        this.min = min;
        this.max = max;
        this.value = start;
        this.samples = [];
        this.lastRate = null;
        this.batchSize = 3;
        this.sinceEval = 0;
    }

    recordPart(bytes, ms) {
        if (!bytes || !ms) return;
        this.samples.push({ bytes, ms });
        if (this.samples.length > 12) this.samples.shift();
        this.sinceEval++;

        if (this.sinceEval < this.batchSize || this.samples.length < 3) return;
        this.sinceEval = 0;

        const last = this.samples.slice(-this.batchSize);
        const rate = last.reduce((s, x) => s + x.bytes / x.ms, 0) / last.length;

        if (this.lastRate === null) {
            this.lastRate = rate;
            // primeira medida: se estamos no mínimo, tenta subir
            if (this.value < this.max) this.value += 1;
            return;
        }
        if (rate > this.lastRate * 1.10 && this.value < this.max) this.value += 1;
        else if (rate < this.lastRate * 0.85 && this.value > this.min) this.value -= 1;
        this.lastRate = rate;
    }

    recordFailure() {
        if (this.value > this.min) this.value -= 1;
        this.lastRate = null; // reset baseline
    }
}
