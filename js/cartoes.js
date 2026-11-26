(() => {
    'use strict';

    const form = document.getElementById('setup-card-form');
    if (!form) return;

    const publishableKey = form.dataset.publishableKey || '';
    const clientSecret = form.dataset.clientSecret || '';
    const returnUrl = form.dataset.returnUrl || '';
    const button = document.getElementById('setup-card-button');
    const errorBox = document.getElementById('setup-card-error');
    const paymentBox = document.getElementById('setup-payment-element');
    const originalText = button ? button.textContent.trim() : 'Confirmar e guardar cartão';

    const mostrarErro = (mensagem) => {
        if (!errorBox) return;
        errorBox.textContent = mensagem || 'Não foi possível validar o cartão. Tenta novamente.';
        errorBox.classList.add('is-visible');
    };

    if (!publishableKey || !clientSecret || !returnUrl || typeof Stripe !== 'function') {
        mostrarErro('Não foi possível preparar a validação segura da Stripe. Atualiza a página e tenta novamente.');
        if (button) button.disabled = true;
        return;
    }

    const tokens = getComputedStyle(document.documentElement);
    const valorToken = (nome, fallback) => tokens.getPropertyValue(nome).trim() || fallback;
    const temaEscuro = document.documentElement.dataset.tema === 'escuro';
    const stripe = Stripe(publishableKey);
    const elements = stripe.elements({
        clientSecret,
        locale: 'pt',
        appearance: {
            theme: temaEscuro ? 'night' : 'stripe',
            variables: {
                colorPrimary: valorToken('--primaria', '#1f4e5f'),
                colorText: valorToken('--texto', '#1c2b2f'),
                colorDanger: valorToken('--erro', '#a8321f'),
                fontFamily: valorToken('--fonte-corpo', 'Inter, system-ui, sans-serif'),
                fontSizeBase: '14px',
                borderRadius: valorToken('--raio-md', '10px'),
                spacingUnit: '4px'
            }
        }
    });

    const paymentElement = elements.create('payment', {
        layout: { type: 'accordion', defaultCollapsed: false, visibleAccordionItemsCount: 1 },
        paymentMethodOrder: ['card'],
        wallets: { link: 'never', applePay: 'never', googlePay: 'never' }
    });
    paymentElement.mount('#setup-payment-element');
    if (paymentBox) paymentBox.classList.add('is-loading');

    paymentElement.on('ready', () => {
        if (paymentBox) paymentBox.classList.remove('is-loading');
        if (button) button.disabled = false;
    });

    paymentElement.on('change', (event) => {
        if (event.error) mostrarErro(event.error.message);
        else if (errorBox) {
            errorBox.textContent = '';
            errorBox.classList.remove('is-visible');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.classList.remove('is-visible');
        }
        if (button) {
            button.disabled = true;
            button.textContent = 'A validar o cartão…';
        }

        try {
            const resultado = await stripe.confirmSetup({
                elements,
                confirmParams: { return_url: returnUrl },
                redirect: 'if_required'
            });

            if (resultado.error) {
                mostrarErro(resultado.error.message);
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
                return;
            }

            const setupIntent = resultado.setupIntent;
            if (setupIntent && setupIntent.status === 'succeeded') {
                const destino = new URL(returnUrl, window.location.href);
                destino.searchParams.set('setup_intent', setupIntent.id);
                window.location.assign(destino.toString());
                return;
            }

            mostrarErro('A Stripe ainda não confirmou o cartão. Segue a indicação apresentada ou tenta novamente.');
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        } catch (erro) {
            console.error('Stripe confirmSetup:', erro);
            mostrarErro('Não foi possível contactar a Stripe. Verifica a ligação e tenta novamente.');
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    });
})();
