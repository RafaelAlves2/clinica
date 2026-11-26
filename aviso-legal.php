<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/conteudo.php';
$pdo = db();

$tituloPagina = conteudo('legal_titulo', 'Aviso legal');
require __DIR__ . '/includes/cabecalho.php';
?>

<section class="interior-hero">
    <div class="interior-hero-top">
        <div class="interior-hero-kicker">
            <span class="kicker-num">Legal</span>
            <span class="kicker-line" aria-hidden="true"></span>
            <span>ERS · OMD</span>
        </div>
        <span class="interior-hero-aside"><?= e(clinica_dado($pdo, 'clinica_ers', CLINICA_ERS)) ?></span>
    </div>
    <h1><?= e(conteudo('legal_titulo', 'Aviso legal')) ?><span class="ponto">.</span></h1>
    <div class="interior-hero-bottom">
        <p>
            Identificação do prestador, natureza da informação e publicidade em saúde.
        </p>
        <span>Informação obrigatória ↓</span>
    </div>
</section>

<?php $intro = trim(conteudo('legal_intro', '')); if ($intro !== ''): ?>
    <p><?= nl2br(e($intro)) ?></p>
<?php endif; ?>

<section class="cartao seccao">
    <h2>Identificação do prestador</h2>
    <dl class="dados">
        <dt>Denominação</dt><dd><?= e(clinica_dado($pdo, 'clinica_nome', CLINICA_NOME)) ?></dd>
        <dt>NIF</dt><dd><?= e(clinica_dado($pdo, 'clinica_nif', CLINICA_NIF)) ?></dd>
        <dt>Registo ERS</dt><dd><?= e(clinica_dado($pdo, 'clinica_ers', CLINICA_ERS)) ?></dd>
        <dt>Licença de funcionamento ERS</dt><dd><?= e(config_valor_texto($pdo, 'licenca_funcionamento_ers', '—')) ?></dd>
        <dt>Morada</dt><dd><?= e(clinica_dado($pdo, 'clinica_morada', CLINICA_MORADA)) ?></dd>
        <dt>Telefone</dt><dd><?= e(clinica_dado($pdo, 'contacto_telefone', CLINICA_TELEFONE)) ?></dd>
        <dt>Email</dt><dd><?= e(clinica_dado($pdo, 'contacto_email', CLINICA_EMAIL)) ?></dd>
        <dt>Diretor clínico</dt><dd><?= e(clinica_dado($pdo, 'clinica_diretor', CLINICA_DIRETOR)) ?> (<?= e(clinica_dado($pdo, 'clinica_diretor_omd', CLINICA_DIRETOR_OMD)) ?>)</dd>
    </dl>
</section>

<section class="cartao seccao">
    <h2>Natureza da informação</h2>
    <p>
        Os conteúdos deste site têm carácter meramente informativo e não
        constituem, em caso algum, prestação de cuidados de saúde, diagnóstico,
        aconselhamento clínico ou proposta comercial vinculativa. A informação
        disponível não substitui uma consulta de avaliação clínica presencial.
    </p>
    <p>
        Os preços apresentados, quando indicados, são meramente indicativos e o
        valor final de cada tratamento é sempre determinado após avaliação
        clínica pelo médico dentista responsável.
    </p>
</section>

<section class="cartao seccao">
    <h2>Publicidade em saúde</h2>
    <p>
        Em cumprimento das normas da Entidade Reguladora da Saúde (ERS) e da
        Ordem dos Médicos Dentistas (OMD), este site:
    </p>
    <ul>
        <li>Não apresenta promessas de resultados clínicos;</li>
        <li>Não utiliza expressões que possam induzir artificialmente a procura de tratamentos;</li>
        <li>Não apresenta imagens de resultados clínicos (antes/depois), mesmo com consentimento;</li>
        <li>Publica avaliações de pacientes de forma anónima, após moderação;</li>
        <li>Identifica claramente que os valores são indicativos e sujeitos a avaliação;</li>
        <li>Identifica o diretor clínico e a cédula profissional dos médicos dentistas.</li>
    </ul>
</section>

<section class="cartao seccao">
    <h2>Especialidades reconhecidas pela OMD</h2>
    <p>
        Apenas as seguintes especialidades são reconhecidas pela Ordem dos
        Médicos Dentistas: <strong>Ortodontia</strong>,
        <strong>Odontopediatria</strong>, <strong>Cirurgia Oral</strong> e
        <strong>Periodontologia</strong>. Outras áreas (como Prostodontia,
        Dentisteria ou Estética Dentária) são designadas neste site como
        "áreas de atuação" e não como especialidades.
    </p>
</section>

<section class="cartao seccao">
    <h2>Reclamações</h2>
    <p>
        O Livro de Reclamações está disponível na receção da clínica e
        eletronicamente em
        <a href="https://www.livroreclamacoes.pt" target="_blank" rel="noopener">www.livroreclamacoes.pt</a>.
    </p>
    <p>
        As reclamações são registadas no Sistema de Gestão de Reclamações
        (SGREC) da ERS no prazo legalmente previsto. O utente pode também
        reclamar diretamente à ERS (<a href="https://www.ers.pt" target="_blank" rel="noopener">www.ers.pt</a>)
        e à Ordem dos Médicos Dentistas (<a href="https://www.omd.pt" target="_blank" rel="noopener">www.omd.pt</a>).
    </p>
</section>

<section class="cartao seccao">
    <h2>Propriedade intelectual</h2>
    <p>
        Todos os conteúdos deste site (textos, imagens, logótipos) são
        propriedade de <?= e(clinica_dado($pdo, 'clinica_nome', CLINICA_NOME)) ?> ou dos respetivos autores, sendo
        proibida a sua reprodução sem autorização prévia.
    </p>
</section>

<p><a class="botao" href="index.php">← Voltar ao início</a></p>

<?php require __DIR__ . '/includes/rodape.php'; ?>