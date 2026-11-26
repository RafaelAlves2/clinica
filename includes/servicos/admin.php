<?php
declare(strict_types=1);

/*
 * includes/servicos/admin.php — estrutura da área de administração
 *
 * Fonte única de verdade para:
 *   - as seis áreas de gestão da consola;
 *   - as funcionalidades existentes em cada área;
 *   - as permissões de acesso por papel;
 *   - a navegação de contexto e os cartões das páginas de entrada.
 *
 * Este ficheiro não liga à base de dados nem imprime nada sozinho.
 */

/**
 * Devolve a definição completa das áreas de administração.
 *
 * Cada funcionalidade aponta sempre para uma página já existente. A área
 * "Locais" usa a página de configurações porque a morada, o mapa e os
 * contactos da clínica são aí definidos.
 *
 * @return array<string, array<string, mixed>>
 */
function admin_seccoes(): array
{
    static $seccoes = null;
    if ($seccoes !== null) {
        return $seccoes;
    }

    $seccoes = [
        'gestao-clinica' => [
            'titulo'       => 'Gestão clínica',
            'descricao'    => 'Organiza a equipa, os horários, os locais e os serviços clínicos que a clínica disponibiliza.',
            'icone'        => 'clinica',
            'url'          => 'admin/seccao-gestao-clinica.php',
            'papeis'       => ['admin'],
            'paginas'      => [
                'seccao-gestao-clinica.php',
                'dentistas.php',
                'horarios.php',
                'bloqueios.php',
                'especialidades.php',
                'tratamentos.php',
                'unidades.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Gerir dentistas',
                    'descricao' => 'Perfis, contactos, especialidades e estado de atividade da equipa clínica.',
                    'url'       => 'admin/dentistas.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Horários',
                    'descricao' => 'Horário de funcionamento da clínica e disponibilidade de cada dentista.',
                    'url'       => 'admin/horarios.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Unidades',
                    'descricao' => 'Adiciona, edita, desativa ou remove unidades e define a localização de cada uma no mapa.',
                    'url'       => 'admin/unidades.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Férias e folgas',
                    'descricao' => 'Bloqueios de agenda para férias, folgas, formação ou imprevistos.',
                    'url'       => 'admin/bloqueios.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Especialidades',
                    'descricao' => 'Áreas clínicas disponíveis e associação aos dentistas da equipa.',
                    'url'       => 'admin/especialidades.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Tratamentos',
                    'descricao' => 'Catálogo de tratamentos, preços, duração e estado de disponibilidade.',
                    'url'       => 'admin/tratamentos.php',
                    'papeis'    => ['admin'],
                ],
            ],
        ],

        'comunicacao' => [
            'titulo'       => 'Comunicação',
            'descricao'    => 'Gere os conteúdos e as mensagens que os pacientes veem e enviam para a clínica.',
            'icone'        => 'comunicacao',
            'url'          => 'admin/seccao-comunicacao.php',
            'papeis'       => ['admin', 'rececao'],
            'paginas'      => [
                'seccao-comunicacao.php',
                'contactos.php',
                'artigos.php',
                'perguntas-frequentes.php',
                'galeria.php',
                'hero.php',
                'testemunhos.php',
                'parceiros.php',
                'avaliacoes.php',
                'candidaturas.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Mensagens de contacto',
                    'descricao' => 'Mensagens enviadas pelo formulário do site, com marcação de lidas.',
                    'url'       => 'admin/contactos.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Artigos',
                    'descricao' => 'Conteúdos de informação ao paciente publicados no blog.',
                    'url'       => 'admin/artigos.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Perguntas frequentes',
                    'descricao' => 'Perguntas e respostas que ajudam os pacientes antes da consulta.',
                    'url'       => 'admin/perguntas-frequentes.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Galeria',
                    'descricao' => 'Imagens da clínica e dos tratamentos apresentadas no site.',
                    'url'       => 'admin/galeria.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Imagens do hero',
                    'descricao' => 'Imagens de destaque da página inicial do site.',
                    'url'       => 'admin/hero.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Testemunhos',
                    'descricao' => 'Testemunhos de pacientes, com controlo de visibilidade e consentimento.',
                    'url'       => 'admin/testemunhos.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Marcas parceiras',
                    'descricao' => 'Logótipos e ligações das marcas parceiras da clínica.',
                    'url'       => 'admin/parceiros.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Avaliações',
                    'descricao' => 'Moderação das avaliações recebidas e publicação anónima.',
                    'url'       => 'admin/avaliacoes.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Candidaturas',
                    'descricao' => 'Candidaturas espontâneas e resposta aos candidatos.',
                    'url'       => 'admin/candidaturas.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
            ],
        ],

        'marcacoes' => [
            'titulo'       => 'Marcações',
            'descricao'    => 'Acompanha a agenda, a lista de espera e os pedidos de alteração de consultas.',
            'icone'        => 'marcacoes',
            'url'          => 'admin/seccao-marcacoes.php',
            'papeis'       => ['admin', 'rececao'],
            'paginas'      => [
                'seccao-marcacoes.php',
                'marcacoes.php',
                'lista-espera.php',
                'pedidos.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Agenda e marcações',
                    'descricao' => 'Vista diária da agenda, estados das consultas e gestão de marcações.',
                    'url'       => 'admin/marcacoes.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Lista de espera',
                    'descricao' => 'Pedidos de pacientes à espera de vaga para uma consulta.',
                    'url'       => 'admin/lista-espera.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Pedidos de alteração',
                    'descricao' => 'Pedidos de alteração de data feitos pelos pacientes, para aprovar ou recusar.',
                    'url'       => 'admin/pedidos.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
            ],
        ],

        'pagamentos' => [
            'titulo'       => 'Pagamentos',
            'descricao'    => 'Controla pagamentos, reembolsos, seguros, benefícios e indicadores financeiros.',
            'icone'        => 'pagamentos',
            'url'          => 'admin/seccao-pagamentos.php',
            'papeis'       => ['admin', 'rececao'],
            'paginas'      => [
                'seccao-pagamentos.php',
                'pagamentos.php',
                'financeiro.php',
                'seguradoras-planos.php',
                'beneficios.php',
                'relatorios.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Pagamentos',
                    'descricao' => 'Registo de pagamentos, estados, reembolsos e dívidas em aberto.',
                    'url'       => 'admin/pagamentos.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Relatório financeiro',
                    'descricao' => 'Recebimentos, valores pendentes e reembolsos por período.',
                    'url'       => 'admin/financeiro.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Seguradoras e planos',
                    'descricao' => 'Entidades, planos e regras de cobertura dos seguros dentários e de saúde.',
                    'url'       => 'admin/seguradoras-planos.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Benefícios e seguros',
                    'descricao' => 'Benefícios associados a pacientes e validação de seguros.',
                    'url'       => 'admin/beneficios.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
                [
                    'titulo'    => 'Relatórios',
                    'descricao' => 'Consultas, receita, tratamentos e atividade por dentista.',
                    'url'       => 'admin/relatorios.php',
                    'papeis'    => ['admin', 'rececao'],
                ],
            ],
        ],

        'recursos' => [
            'titulo'       => 'Recursos materiais',
            'descricao'    => 'Mantém o stock, as encomendas e os movimentos de materiais sob controlo.',
            'icone'        => 'recursos',
            'url'          => 'admin/seccao-recursos.php',
            'papeis'       => ['admin'],
            'paginas'      => [
                'seccao-recursos.php',
                'stock.php',
                'stock-encomendas.php',
                'stock-categorias.php',
                'stock-movimentos.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Stock de materiais',
                    'descricao' => 'Inventário, quantidades mínimas e alertas de reposição.',
                    'url'       => 'admin/stock.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Encomendas',
                    'descricao' => 'Encomendas a fornecedores e acompanhamento do estado de entrega.',
                    'url'       => 'admin/stock-encomendas.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Categorias de stock',
                    'descricao' => 'Organização dos materiais por categorias.',
                    'url'       => 'admin/stock-categorias.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Movimentos de stock',
                    'descricao' => 'Entradas, saídas e histórico de alterações ao inventário.',
                    'url'       => 'admin/stock-movimentos.php',
                    'papeis'    => ['admin'],
                ],
            ],
        ],

        'sistema' => [
            'titulo'       => 'Administração do sistema',
            'descricao'    => 'Configura utilizadores, permissões, definições gerais e a auditoria da plataforma.',
            'icone'        => 'sistema',
            'url'          => 'admin/seccao-sistema.php',
            'papeis'       => ['admin'],
            'paginas'      => [
                'seccao-sistema.php',
                'utilizadores.php',
                'utilizadores-novo.php',
                'configuracoes.php',
                'importar-dados.php',
                'editor-site.php',
                'auditoria.php',
            ],
            'funcionalidades' => [
                [
                    'titulo'    => 'Utilizadores e permissões',
                    'descricao' => 'Contas, papéis e acesso às diferentes áreas da clínica.',
                    'url'       => 'admin/utilizadores.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Novo utilizador',
                    'descricao' => 'Criação de uma conta de acesso à plataforma.',
                    'url'       => 'admin/utilizadores-novo.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Configurações',
                    'descricao' => 'Identidade, contactos, pagamentos, emails e definições da clínica.',
                    'url'       => 'admin/configuracoes.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Importar dados de outro sistema',
                    'descricao' => 'Analisa exportações CSV e prepara a migração de dados de sistemas antigos, sem alterar os registos atuais.',
                    'url'       => 'admin/importar-dados.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Editor do site',
                    'descricao' => 'Textos e conteúdos gerais do site institucional.',
                    'url'       => 'admin/editor-site.php',
                    'papeis'    => ['admin'],
                ],
                [
                    'titulo'    => 'Auditoria',
                    'descricao' => 'Registo das ações administrativas para rastreabilidade.',
                    'url'       => 'admin/auditoria.php',
                    'papeis'    => ['admin'],
                ],
            ],
        ],
    ];

    return $seccoes;
}

/**
 * Devolve uma área pelo identificador, ou null se não existir.
 *
 * @return array<string, mixed>|null
 */
function admin_seccao(string $chave): ?array
{
    $seccoes = admin_seccoes();
    return $seccoes[$chave] ?? null;
}

/**
 * Indica se o papel atual pode ver uma área ou funcionalidade.
 *
 * @param array<string, mixed> $item
 */
function admin_pode_ver(array $item, string $papel): bool
{
    $papeis = $item['papeis'] ?? ['admin', 'rececao'];
    return in_array($papel, $papeis, true);
}

/**
 * Descobre a área correspondente ao ficheiro atual.
 */
function admin_seccao_atual(string $pagina): ?string
{
    foreach (admin_seccoes() as $chave => $seccao) {
        if (in_array($pagina, $seccao['paginas'] ?? [], true)) {
            return $chave;
        }
    }
    return null;
}

/**
 * Devolve um ícone SVG inline, sem dependências externas.
 */
function admin_icone(string $nome, int $tamanho = 24): string
{
    $conteudo = match ($nome) {
        'clinica' => '<path d="M12 4c-2.8 0-5 1.8-5 4.4 0 1.4.6 2.5 1.1 3.7.4 1 .6 2 .7 3.1.1 1.4.5 2.3 1.3 2.3.8 0 1.1-1 1.5-2.3.3-1.1.6-1.8 1.4-1.8s1.1.7 1.4 1.8c.4 1.3.7 2.3 1.5 2.3.8 0 1.2-.9 1.3-2.3.1-1.1.3-2.1.7-3.1.5-1.2 1.1-2.3 1.1-3.7C17 5.8 14.8 4 12 4Z"/>',
        'comunicacao' => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.6 9.6 0 0 1-4.2-.9L3 21l1.9-4.7A8.4 8.4 0 1 1 21 11.5Z"/><path d="M8 10h8M8 14h5"/>',
        'marcacoes' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'pagamentos' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'recursos' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
        'sistema' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'seta' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'voltar' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        default => '<circle cx="12" cy="12" r="9"/>',
    };

    return '<svg width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true" focusable="false">' . $conteudo . '</svg>';
}

/**
 * Navegação de contexto apresentada em todas as páginas /admin/.
 */
function admin_subnav(string $pagina, array $u): string
{
    $papel = (string)($u['papel'] ?? '');
    $ativo = admin_seccao_atual($pagina);

    $html = '<nav class="adm-subnav" aria-label="Secções da administração">';
    $html .= '<div class="adm-subnav-inner">';
    $html .= '<a class="adm-subnav-home" href="admin/index.php"'
        . ($pagina === 'index.php' ? ' aria-current="page"' : '') . '>Painel</a>';
    $html .= '<span class="adm-subnav-divider" aria-hidden="true"></span>';

    foreach (admin_seccoes() as $chave => $seccao) {
        if (!admin_pode_ver($seccao, $papel)) {
            continue;
        }
        $html .= '<a href="' . e((string)$seccao['url']) . '"'
            . ($ativo === $chave ? ' aria-current="page"' : '') . '>'
            . e((string)$seccao['titulo']) . '</a>';
    }

    $html .= '</div></nav>';
    return $html;
}

/**
 * Cartões das seis áreas, usados na página inicial da administração.
 */
function admin_seccoes_html(array $u): string
{
    $papel = (string)($u['papel'] ?? '');
    $html = '';

    foreach (admin_seccoes() as $chave => $seccao) {
        if (!admin_pode_ver($seccao, $papel)) {
            continue;
        }

        $funcionalidades = array_values(array_filter(
            $seccao['funcionalidades'] ?? [],
            static fn(array $f): bool => admin_pode_ver($f, $papel)
        ));
        if (!$funcionalidades) {
            continue;
        }

        $total = count($funcionalidades);
        $rotuloTotal = $total === 1 ? '1 funcionalidade' : $total . ' funcionalidades';

        $html .= '<a class="adm-section-card" href="' . e((string)$seccao['url']) . '">';
        $html .= '<span class="adm-section-count">' . e($rotuloTotal) . '</span>';
        $html .= '<span class="adm-section-icon">' . admin_icone((string)$seccao['icone'], 22) . '</span>';
        $html .= '<h2>' . e((string)$seccao['titulo']) . '</h2>';
        $html .= '<p>' . e((string)$seccao['descricao']) . '</p>';
        $html .= '<span class="adm-section-action">Abrir secção ' . admin_icone('seta', 15) . '</span>';
        $html .= '</a>';
    }

    return $html;
}

/**
 * Conteúdo de uma página de entrada de área.
 */
function admin_hub_html(string $chave, array $u): string
{
    $seccao = admin_seccao($chave);
    if ($seccao === null || !admin_pode_ver($seccao, (string)($u['papel'] ?? ''))) {
        return '';
    }

    $funcionalidades = array_values(array_filter(
        $seccao['funcionalidades'] ?? [],
        static fn(array $f): bool => admin_pode_ver($f, (string)($u['papel'] ?? ''))
    ));

    $html = '<nav class="adm-breadcrumb" aria-label="Caminho">';
    $html .= '<a href="admin/index.php">Painel</a>';
    $html .= '<span aria-hidden="true">/</span>';
    $html .= '<span aria-current="page">' . e((string)$seccao['titulo']) . '</span>';
    $html .= '</nav>';

    $html .= '<div class="dash-page-top">';
    $html .= '<div>';
    $html .= '<span class="dash-kicker">Área de gestão</span>';
    $html .= '<h1>' . e((string)$seccao['titulo']) . '</h1>';
    $html .= '<p>' . e((string)$seccao['descricao']) . '</p>';
    $html .= '</div>';
    $html .= '<span class="adm-page-icon" aria-hidden="true">' . admin_icone((string)$seccao['icone'], 28) . '</span>';
    $html .= '</div>';

    if (!$funcionalidades) {
        $html .= '<div class="dash-empty">';
        $html .= '<span class="dash-empty-symbol" aria-hidden="true">◷</span>';
        $html .= '<h2>Sem funcionalidades disponíveis</h2>';
        $html .= '<p>O teu perfil não tem acesso a nenhuma funcionalidade desta área.</p>';
        $html .= '<a class="botao botao-secundario" href="admin/index.php">Voltar ao painel</a>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<div class="adm-feature-grid">';
    foreach ($funcionalidades as $funcionalidade) {
        $html .= '<a class="adm-feature-card" href="' . e((string)$funcionalidade['url']) . '">';
        $html .= '<h2>' . e((string)$funcionalidade['titulo']) . '</h2>';
        $html .= '<p>' . e((string)$funcionalidade['descricao']) . '</p>';
        $html .= '<span class="adm-feature-action">Abrir ' . admin_icone('seta', 14) . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';

    $html .= '<div class="adm-hub-footer">';
    $html .= '<a class="botao botao-secundario" href="admin/index.php">' . admin_icone('voltar', 15) . ' Voltar ao painel</a>';
    $html .= '<span class="ajuda">' . count($funcionalidades) . ' '
        . (count($funcionalidades) === 1 ? 'funcionalidade disponível' : 'funcionalidades disponíveis')
        . ' nesta área.</span>';
    $html .= '</div>';

    return $html;
}
