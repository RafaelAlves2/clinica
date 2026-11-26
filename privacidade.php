<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';

$tituloPagina = conteudo('privacidade_titulo', 'Política de Privacidade');
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">Legal</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>RGPD · Lei 58/2019</span>
        </div>
        <span class="interior-hero-aside">Última atualização: 2026</span>
    </div>
    <h1><?= e(conteudo('privacidade_titulo', 'Política de Privacidade')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Em conformidade com o Regulamento (UE) 2016/679 (RGPD) e a Lei n.º 58/2019.
        </p>
        <span>Lê com atenção ↓</span>
    </div>
</section>

<?php $__ci = trim(conteudo('privacidade_intro', '')); if ($__ci !== ''): ?>
    <p><?= nl2br(e($__ci)) ?></p>
<?php endif; ?>

<section class="cartao seccao">
    <h2>1. Responsável pelo tratamento</h2>
    <p>
        O responsável pelo tratamento dos dados pessoais recolhidos através
        deste site é <strong><?= e(CLINICA_NOME) ?></strong>, com sede em
        <?= e(CLINICA_MORADA) ?>, NIF <?= e(CLINICA_NIF) ?>, registada na
        Entidade Reguladora da Saúde (ERS) sob o n.º <?= e(CLINICA_ERS) ?>.
    </p>
    <p>
        Contacto do Encarregado de Proteção de Dados (DPO):
        <a href="mailto:<?= e(CLINICA_EMAIL) ?>"><?= e(CLINICA_EMAIL) ?></a>.
    </p>
</section>

<section class="cartao seccao">
    <h2>2. Finalidades e fundamentos de licitude</h2>
    <p>Os dados pessoais são tratados para as seguintes finalidades:</p>
    <ul>
        <li><strong>Gestão da conta e da área pessoal</strong> — execução de contrato (art. 6.º, n.º 1, al. b) do RGPD);</li>
        <li><strong>Marcação e gestão de consultas</strong> — execução de contrato e prestação de cuidados de saúde (art. 6.º, n.º 1, al. b) e art. 9.º, n.º 2, al. h) do RGPD);</li>
        <li><strong>Prestação de cuidados de saúde</strong> — dados de saúde tratados por profissionais sujeitos a sigilo profissional (art. 9.º, n.º 2, al. h) do RGPD);</li>
        <li><strong>Comunicações e notificações</strong> — execução de contrato e consentimento;</li>
        <li><strong>Gestão de seguros e benefícios</strong> — consentimento do titular (art. 6.º, n.º 1, al. a) do RGPD);</li>
        <li><strong>Avaliações e feedback</strong> — consentimento do titular, publicado de forma anónima;</li>
        <li><strong>Cumprimento de obrigações legais</strong> — art. 6.º, n.º 1, al. c) do RGPD (ex.: faturação, registos clínicos).</li>
    </ul>
</section>

<section class="cartao seccao">
    <h2>3. Dados recolhidos</h2>
    <p>Podem ser recolhidos os seguintes dados:</p>
    <ul>
        <li>Dados de identificação: nome completo, data de nascimento, NIF, número de utente SNS;</li>
        <li>Dados de contacto: email, telemóvel, morada;</li>
        <li>Dados de saúde: histórico clínico, tratamentos, diagnósticos, imagens clínicas, prescrições;</li>
        <li>Dados de marcação: consultas, tratamentos, pagamentos;</li>
        <li>Dados de navegação: endereço IP, tipo de dispositivo, páginas visitadas (apenas os estritamente necessários ao funcionamento do site).</li>
    </ul>
</section>

<section class="cartao seccao">
    <h2>4. Conservação</h2>
    <p>
        Os dados de saúde são conservados pelo prazo legalmente exigido para o
        processo clínico (mínimo de 5 anos após o último ato clínico, nos termos
        da legislação aplicável). Os restantes dados são conservados enquanto a
        conta estiver ativa e, após o seu encerramento, pelo prazo necessário ao
        cumprimento de obrigações legais.
    </p>
</section>

<section class="cartao seccao">
    <h2>5. Partilha de dados</h2>
    <p>
        Os dados podem ser partilhados com: seguradoras e planos de saúde (no
        âmbito dos benefícios que o titular ativar), prestadores de serviços de
        pagamento, prestadores de serviços informáticos (alojamento, email), e
        autoridades públicas quando legalmente exigido. Não são transferidos
        para fora do Espaço Económico Europeu sem garantias adequadas.
    </p>
    <p>
        Se o titular optar por guardar um cartão na área pessoal, os dados do cartão
        são recolhidos e conservados pela Stripe para permitir a sua apresentação
        em pagamentos futuros iniciados pelo próprio titular. A clínica conserva
        apenas os identificadores do cliente e do método de pagamento na Stripe e
        o registo do consentimento. O número completo do cartão e o CVC não são
        recebidos nem guardados pela clínica. O cartão guardado só é apresentado
        quando o titular tem sessão iniciada e cada pagamento exige confirmação
        expressa. O consentimento pode ser retirado na área «Cartões», removendo
        o cartão guardado.
    </p>
</section>

<section class="cartao seccao">
    <h2>6. Direitos do titular</h2>
    <p>O titular dos dados pode, a qualquer momento, exercer os seguintes direitos:</p>
    <ul>
        <li>Acesso aos dados pessoais que lhe digam respeito;</li>
        <li>Retificação de dados inexatos ou incompletos;</li>
        <li>Apagamento ("direito ao esquecimento"), nos casos legalmente previstos;</li>
        <li>Limitação do tratamento;</li>
        <li>Portabilidade dos dados;</li>
        <li>Oposição ao tratamento;</li>
        <li>Retirada do consentimento, sem comprometer a licitude do tratamento anterior.</li>
    </ul>
    <p>
        Para exercer estes direitos, contacte-nos através de
        <a href="mailto:<?= e(CLINICA_EMAIL) ?>"><?= e(CLINICA_EMAIL) ?></a>.
    </p>
    <p>
        O titular tem ainda o direito de apresentar reclamação à Comissão Nacional
        de Proteção de Dados (CNPD) — <a href="https://www.cnpd.pt" target="_blank" rel="noopener">www.cnpd.pt</a>.
    </p>
</section>

<section class="cartao seccao">
    <h2>7. Segurança</h2>
    <p>
        Adotamos medidas técnicas e organizativas adequadas para proteger os
        dados pessoais contra acesso não autorizado, perda ou destruição,
        incluindo: ligações cifradas (HTTPS), controlo de acessos, registo de
        operações e formação dos colaboradores.
    </p>
</section>

<section class="cartao seccao">
    <h2>8. Reclamações</h2>
    <p>
        O Livro de Reclamações está disponível na receção da clínica e
        eletronicamente em
        <a href="https://www.livroreclamacoes.pt" target="_blank" rel="noopener">www.livroreclamacoes.pt</a>.
        As reclamações são também registadas no Sistema de Gestão de Reclamações
        (SGREC) da ERS, nos termos legais aplicáveis.
    </p>
</section>

<p><a class="botao" href="index.php">← Voltar ao início</a></p>

<?php require __DIR__ . '/includes/rodape.php'; ?>