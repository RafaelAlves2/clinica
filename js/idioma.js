/* Idioma do site: PT-PT / EN. Traduz a interface sem alterar dados guardados. */
(function () {
    'use strict';

    var html = document.documentElement;
    var botao = document.getElementById('botao-idioma');
    if (!botao) return;

    var traducoes = {
        'Saltar para o conteúdo principal': 'Skip to main content',
        'Menu principal': 'Main menu',
        'Início': 'Home',
        'Equipa': 'Our Team',
        'Tratamentos': 'Treatments',
        'Clínica': 'Clinic',
        'A Nossa Clínica': 'Our Clinic',
        'Informação ao paciente': 'Patient Information',
        'Seguros e acordos': 'Insurance and Agreements',
        'Contactos': 'Contact',
        'Trabalha connosco': 'Work With Us',
        'Administração': 'Administration',
        'A minha agenda': 'My Schedule',
        'A minha área': 'My Account',
        'Sair': 'Log out',
        'Entrar': 'Log in',
        'Alternar tema': 'Toggle theme',
        'Alternar entre modo claro e modo escuro': 'Toggle between light and dark mode',
        'Ativar modo claro': 'Enable light mode',
        'Ativar modo escuro': 'Enable dark mode',
        'Mudar para modo claro': 'Switch to light mode',
        'Mudar para modo escuro': 'Switch to dark mode',
        'Informação legal': 'Legal Information',
        'Política de Privacidade (RGPD)': 'Privacy Policy (GDPR)',
        'Política de Cookies': 'Cookie Policy',
        'Aviso legal e reclamações': 'Legal Notice and Complaints',
        'Livro de Reclamações eletrónico': 'Electronic Complaints Book',
        'Livro de Reclamações físico disponível na receção.': 'A physical Complaints Book is available at reception.',
        'Todos os direitos reservados.': 'All rights reserved.',
        'Os conteúdos deste site têm carácter informativo e não substituem uma consulta de avaliação clínica. Os preços apresentados, quando indicados, são meramente indicativos.': 'The content on this website is for information purposes only and does not replace a clinical assessment. Any prices shown are indicative only.',
        'O teu sorriso em boas mãos': 'Your smile in good hands',
        'Marca a tua consulta online, escolhe o teu médico dentista e acompanha tudo na tua área pessoal.': 'Book your appointment online, choose your dentist and manage everything in your personal area.',
        'Marcar consulta': 'Book an appointment',
        'Os nossos serviços': 'Our Services',
        'Os valores abaixo são meramente indicativos. O preço final de cada tratamento é determinado após consulta de avaliação clínica, de acordo com o diagnóstico e o plano de tratamento definidos pelo médico dentista.': 'The prices below are indicative only. The final price of each treatment is determined after a clinical assessment, according to the diagnosis and treatment plan defined by the dentist.',
        'Sob avaliação': 'Upon assessment',
        'Ver mais': 'See more',
        'Ver mais tratamentos': 'See more treatments',
        'Ver todos os tratamentos →': 'View all treatments →',
        'Ainda não há tratamentos publicados.': 'There are no published treatments yet.',
        'A nossa equipa': 'Our Team',
        'Áreas de atuação:': 'Areas of practice:',
        'Ver mais membros da equipa': 'See more team members',
        'Ver toda a equipa →': 'View the whole team →',
        'Ainda não há médicos dentistas publicados.': 'There are no dentists published yet.',
        'Siga-nos': 'Follow us',
        'Canais oficiais da clínica. Apenas são apresentados os canais configurados.': 'Official clinic channels. Only configured channels are displayed.',
        'A clínica': 'The Clinic',
        'Fotografia da clínica': 'Clinic photograph',
        'As imagens de instalações e equipa são meramente ilustrativas. Não são apresentadas imagens de resultados clínicos (antes/depois), em cumprimento das normas da Ordem dos Médicos Dentistas.': 'Images of the facilities and team are for illustrative purposes only. Clinical before-and-after images are not displayed, in accordance with the rules of the Portuguese Dental Association.',
        'Horário': 'Opening Hours',
        'Horário por definir.': 'Opening hours to be confirmed.',
        'Contactos e localização': 'Contact and Location',
        'Telefone:': 'Phone:',
        'Ver página de contactos →': 'View contact page →',
        'Onde estamos': 'Find Us',
        'Perguntas frequentes': 'Frequently Asked Questions',
        'NIF:': 'Tax ID:',
        'Registo ERS n.º': 'ERS Registration No.',
        'Tel.:': 'Tel.:',
        'Diretor clínico:': 'Clinical Director:',
        'Nome': 'Name',
        'Email': 'Email',
        'E-mail': 'Email',
        'Telefone': 'Phone',
        'Telemóvel': 'Mobile phone',
        'Morada': 'Address',
        'Data': 'Date',
        'Hora': 'Time',
        'Estado': 'Status',
        'Ações': 'Actions',
        'Ação': 'Action',
        'Guardar': 'Save',
        'Cancelar': 'Cancel',
        'Eliminar': 'Delete',
        'Editar': 'Edit',
        'Adicionar': 'Add',
        'Pesquisar': 'Search',
        'Filtrar': 'Filter',
        'Limpar filtros': 'Clear filters',
        'Voltar': 'Back',
        'Continuar': 'Continue',
        'Confirmar': 'Confirm',
        'Enviar': 'Submit',
        'Atualizar': 'Update',
        'Ver detalhes': 'View details',
        'Detalhes': 'Details',
        'Descrição': 'Description',
        'Observações': 'Notes',
        'Preço': 'Price',
        'Duração': 'Duration',
        'Especialidade': 'Specialty',
        'Especialidades': 'Specialties',
        'Dentista': 'Dentist',
        'Paciente': 'Patient',
        'Pacientes': 'Patients',
        'Utilizador': 'User',
        'Utilizadores': 'Users',
        'Marcação': 'Appointment',
        'Marcações': 'Appointments',
        'Consulta': 'Appointment',
        'Consultas': 'Appointments',
        'Pagamento': 'Payment',
        'Pagamentos': 'Payments',
        'Tratamento': 'Treatment',
        'Serviço': 'Service',
        'Serviços': 'Services',
        'Stock': 'Inventory',
        'Categoria': 'Category',
        'Categorias': 'Categories',
        'Quantidade': 'Quantity',
        'Fornecedor': 'Supplier',
        'Fornecedores': 'Suppliers',
        'Encomenda': 'Order',
        'Encomendas': 'Orders',
        'Relatórios': 'Reports',
        'Configurações': 'Settings',
        'Galeria': 'Gallery',
        'Parceiros': 'Partners',
        'Testemunhos': 'Testimonials',
        'Avaliações': 'Reviews',
        'Artigos': 'Articles',
        'Blog': 'Blog',
        'Horários': 'Schedules',
        'Seguros': 'Insurance',
        'Seguradoras': 'Insurance Companies',
        'Benefícios': 'Benefits',
        'Candidaturas': 'Applications',
        'Contactos': 'Contacts',
        'Auditoria': 'Audit Log',
        'Lista de espera': 'Waiting List',
        'Bloqueios': 'Blocked Slots',
        'Ativo': 'Active',
        'Ativa': 'Active',
        'Inativo': 'Inactive',
        'Inativa': 'Inactive',
        'Pendente': 'Pending',
        'Concluído': 'Completed',
        'Concluída': 'Completed',
        'Pago': 'Paid',
        'Paga': 'Paid',
        'Por pagar': 'Unpaid',
        'Sim': 'Yes',
        'Não': 'No',
        'Nenhum resultado encontrado.': 'No results found.',
        'Não existem registos.': 'No records found.',
        'Acesso não autorizado.': 'Unauthorised access.',
        'Ocorreu um erro. Tenta novamente.': 'An error occurred. Please try again.',
        'Obrigatório': 'Required',
        'Campo obrigatório': 'Required field',
        'Selecionar': 'Select',
        'Seleciona uma opção': 'Select an option',
        'Todos': 'All',
        'Todas': 'All',
        'Hoje': 'Today',
        'Ontem': 'Yesterday',
        'Esta semana': 'This week',
        'Este mês': 'This month',
        'Desde': 'From',
        'Até': 'To'
    };

    function lerIdioma() {
        var match = document.cookie.match(/(?:^|; )idioma=([^;]*)/);
        if (match) {
            var valor = decodeURIComponent(match[1]);
            if (valor === 'pt' || valor === 'en') return valor;
        }
        try {
            var guardado = localStorage.getItem('idioma');
            if (guardado === 'pt' || guardado === 'en') return guardado;
        } catch (e) {}
        return 'pt';
    }

    function traduzirTexto(texto, idioma) {
        var limpo = texto.trim();
        if (!limpo) return texto;
        var traducao = traducoes[limpo];
        if (!traducao) return texto;
        return texto.replace(limpo, idioma === 'en' ? traducao : Object.keys(traducoes).find(function (pt) {
            return traducoes[pt] === limpo;
        }) || limpo);
    }

    function aplicar(idioma, guardar) {
        idioma = idioma === 'en' ? 'en' : 'pt';
        html.lang = idioma === 'en' ? 'en' : 'pt-PT';
        html.dataset.idioma = idioma;
        botao.textContent = idioma === 'pt' ? 'PT' : 'EN';
        botao.setAttribute('aria-label', idioma === 'pt' ? 'Mudar idioma para inglês' : 'Change language to Portuguese');
        botao.title = idioma === 'pt' ? 'Mudar para inglês' : 'Switch to Portuguese';
        botao.setAttribute('aria-pressed', idioma === 'en' ? 'true' : 'false');

        document.querySelectorAll('[data-idioma-original]').forEach(function (el) {
            el.textContent = el.getAttribute('data-idioma-original');
            el.removeAttribute('data-idioma-original');
        });

        if (idioma === 'en') {
            var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            var nos = [];
            while (walker.nextNode()) nos.push(walker.currentNode);
            nos.forEach(function (no) {
                var original = no.nodeValue;
                var novo = traduzirTexto(original, 'en');
                if (novo !== original) {
                    no.__textoOriginalIdioma = original;
                    if (window.__nosIdioma.indexOf(no) === -1) window.__nosIdioma.push(no);
no.nodeValue = novo;
                }
            });

            document.querySelectorAll('input[placeholder], textarea[placeholder], button[aria-label], [title]').forEach(function (el) {
                ['placeholder', 'aria-label', 'title'].forEach(function (attr) {
                    var valor = el.getAttribute(attr);
                    if (valor && traducoes[valor]) {
                        el.setAttribute('data-idioma-' + attr, valor);
                        el.setAttribute(attr, traducoes[valor]);
                    }
                });
            });
        } else {
            document.querySelectorAll('*').forEach(function (el) {
                ['placeholder', 'aria-label', 'title'].forEach(function (attr) {
                    var original = el.getAttribute('data-idioma-' + attr);
                    if (original !== null) {
                        el.setAttribute(attr, original);
                        el.removeAttribute('data-idioma-' + attr);
                    }
                });
            });
        }

        // Regista os textos originais para permitir regressar a português sem recarregar.
        if (idioma === 'en') {
            var walker2 = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
            while (walker2.nextNode()) {
                var n = walker2.currentNode;
                if (n.__textoOriginalIdioma) n.__textoTraduzidoIdioma = n.nodeValue;
            }
        } else {
            document.querySelectorAll('script, style').forEach(function () {});
            // Os nós são restaurados através do registo mantido no documento.
            if (window.__nosIdioma) {
                window.__nosIdioma.forEach(function (n) {
                    if (n.__textoOriginalIdioma) {
                        n.nodeValue = n.__textoOriginalIdioma;
                        delete n.__textoOriginalIdioma;
                        delete n.__textoTraduzidoIdioma;
                    }
                });
            }
        }

        if (guardar) {
            document.cookie = 'idioma=' + idioma + '; path=/; max-age=31536000; SameSite=Lax';
            try { localStorage.setItem('idioma', idioma); } catch (e) {}
        }
    }

    // Mantém referências aos nós para alternar de idioma sem perder o texto original.
    window.__nosIdioma = [];
    var observador = new MutationObserver(function () {
        if (html.dataset.idioma !== 'en') return;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            var no = walker.currentNode;
            if (no.__textoOriginalIdioma) continue;
            var original = no.nodeValue;
            var novo = traduzirTexto(original, 'en');
            if (novo !== original) {
                no.__textoOriginalIdioma = original;
                if (window.__nosIdioma.indexOf(no) === -1) window.__nosIdioma.push(no);
no.nodeValue = novo;
                window.__nosIdioma.push(no);
            }
        }
    });

    var idiomaInicial = lerIdioma();
    aplicar(idiomaInicial, false);
    if (idiomaInicial === 'en') {
        var w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (w.nextNode()) if (w.currentNode.__textoOriginalIdioma) window.__nosIdioma.push(w.currentNode);
    }
    observador.observe(document.body, { childList: true, subtree: true });
    botao.addEventListener('click', function () {
        aplicar(html.dataset.idioma === 'en' ? 'pt' : 'en', true);
    });
})();
