import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'confirm', 'bar', 'label', 'rule', 'matchMsg'];

    connect() {
        if (this.hasBarTarget) this._paintBar(0);
    }

    evaluate() {
        const pw = this.inputTarget.value;
        const checks = [
            pw.length >= 8,
            /[A-Z]/.test(pw),
            /[0-9]/.test(pw),
            /[^A-Za-z0-9]/.test(pw),
        ];
        const score = checks.filter(Boolean).length;

        if (this.hasBarTarget)   this._paintBar(score);
        if (this.hasLabelTarget) this._paintLabel(score);

        this.ruleTargets.forEach((el, i) => el.classList.toggle('met', !!checks[i]));

        if (this.hasConfirmTarget && this.confirmTarget.value.length > 0) {
            this._checkMatch();
        }
    }

    matchCheck() {
        this._checkMatch();
    }

    toggleInput(e)   { this._toggleEye(e.currentTarget, this.inputTarget); }
    toggleConfirm(e) { this._toggleEye(e.currentTarget, this.confirmTarget); }

    /* ---- private ---- */

    _checkMatch() {
        if (!this.hasMatchMsgTarget) return;
        const ok = this.inputTarget.value === this.confirmTarget.value
                   && this.confirmTarget.value.length > 0;
        this.matchMsgTarget.textContent  = ok ? '✓ Passwords match' : '✕ Passwords do not match';
        this.matchMsgTarget.dataset.state = ok ? 'ok' : 'err';
    }

    _toggleEye(btn, input) {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        const eyeOn  = btn.querySelector('.eye-on');
        const eyeOff = btn.querySelector('.eye-off');
        if (eyeOn)  eyeOn.hidden  = reveal;
        if (eyeOff) eyeOff.hidden = !reveal;
    }

    _paintBar(score) {
        const widths = ['0%', '25%', '50%', '75%', '100%'];
        const colors = ['', '#e53935', '#fb8c00', '#E8A020', '#2e7d32'];
        this.barTarget.style.width           = widths[score];
        this.barTarget.style.backgroundColor = colors[score] || '';
    }

    _paintLabel(score) {
        const texts  = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        const colors = ['', '#e53935', '#fb8c00', '#E8A020', '#2e7d32'];
        this.labelTarget.textContent = texts[score];
        this.labelTarget.style.color = colors[score] || '';
    }
}
