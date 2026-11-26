(() => {
    'use strict';

    const form = document.getElementById('payment-form');
    if (!form) return;

    const publishableKey = form.dataset.publishableKey || '';
    const clientSecret = form.dataset.clientSecret || '';
    const customerSessionClientSecret = form.dataset.customerSessionSecret || '';
    const returnUrl = form.dataset.returnUrl || '';
    const button = document.getElementById('pay-button');
    const errorBox = document.getElementById('checkout-error');
    const paymentElementBox = document.getElementById('payment-element');
    const textoBotaoOriginal = button ? button.textContent.trim() : 'Pagar';

    const mostrarErro = (mensagem) => {
        if (!errorBox) return;
        errorBox.textContent = mensagem || 'Não foi possível processar o pagamento. Tenta novamente.';
        errorBox.classList.add('is-visible');
        errorBox.setAttribute('role', 'alert');
    };

    const limparErro = () => {
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.classList.remove('is-visible');
    };

    const reporBotao = () => {
        if (!button) return;
        button.disabled = false;
        button.textContent = textoBotaoOriginal;
    };

    if (!publishableKey || !clientSecret || !returnUrl) {
        mostrarErro('O checkout não está configurado corretamente. Contacta a clínica.');
        if (button) button.disabled = true;
        return;
    }
    if (typeof Stripe !== 'function') {
        mostrarErro('Não foi possível carregar o sistema de pagamento. Verifica a ligação e tenta novamente.');
        if (button) button.disabled = true;
        return;
    }

    const tokens = getComputedStyle(document.documentElement);
    const valorToken = (nome, fallback) => tokens.getPropertyValue(nome).trim() || fallback;
    const temaEscuro = document.documentElement.dataset.tema === 'escuro';
    const stripe = Stripe(publishableKey);
    const options = {
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
    };
    if (customerSessionClientSecret) {
        options.customerSessionClientSecret = customerSessionClientSecret;
    }

    const elements = stripe.elements(options);
    const paymentElement = elements.create('payment', {
        layout: { type: 'accordion', defaultCollapsed: false, visibleAccordionItemsCount: 3 },
        paymentMethodOrder: ['mb_way', 'multibanco', 'card'],
        wallets: { link: 'never', applePay: 'never', googlePay: 'never' },
        fields: {
            billingDetails: {
                name: 'never',
                email: 'never',
                phone: 'never',
                address: 'if_required'
            }
        }
    });

    paymentElement.mount('#payment-element');
    if (paymentElementBox) paymentElementBox.classList.add('is-loading');

    paymentElement.on('ready', () => {
        if (paymentElementBox) paymentElementBox.classList.remove('is-loading');
        if (button) button.disabled = false;
    });

    paymentElement.on('change', (event) => {
        if (event.error) {
            mostrarErro(event.error.message);
        } else {
            limparErro();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        limparErro();

        const email = form.querySelector('input[name="email"]');
        const telefone = form.querySelector('input[name="telefone"]');
        const pais = form.querySelector('select[name="pais"]');
        const holder = document.getElementById('holder');

        if (email && !email.checkValidity()) {
            mostrarErro('Introduz um endereço de e-mail válido.');
            email.focus();
            return;
        }

        const emailValor = email ? email.value.trim() : '';
        const nomeTitular = holder && holder.value.trim() !== ''
            ? holder.value.trim()
            : (emailValor || 'Paciente Clínica Dentária');

        if (button) {
            button.disabled = true;
            button.textContent = 'A processar…';
        }

        const billingDetails = {
            name: nomeTitular,
            email: emailValor
        };
        if (telefone && telefone.value.trim() !== '') {
            billingDetails.phone = telefone.value.trim();
        }
        if (pais && pais.value) {
            billingDetails.address = { country: pais.value };
        }

        let resultado;
        try {
            resultado = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: returnUrl,
                    payment_method_data: { billing_details: billingDetails }
                },
                redirect: 'if_required'
            });
        } catch (erro) {
            console.error('Stripe confirmPayment:', erro);
            mostrarErro('Não foi possível contactar a Stripe. Verifica a ligação e tenta novamente.');
            reporBotao();
            return;
        }

        const { error, paymentIntent } = resultado;
        if (error) {
            mostrarErro(error.message);
            reporBotao();
            return;
        }

        if (paymentIntent && (
            paymentIntent.status === 'succeeded' ||
            paymentIntent.status === 'processing' ||
            paymentIntent.status === 'requires_action'
        )) {
            const destino = new URL(returnUrl, window.location.href);
            destino.searchParams.set('payment_intent', paymentIntent.id);
            destino.searchParams.set('redirect_status', paymentIntent.status);
            window.location.assign(destino.toString());
            return;
        }

        mostrarErro('O pagamento não foi concluído. Tenta novamente.');
        reporBotao();
    });
})();
