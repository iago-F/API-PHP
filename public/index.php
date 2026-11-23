<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/CnpjService.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$service = new CnpjService();

// 1. Validar, consultar, salvar/atualizar e retornar via GET /cnpj/{cnpj}
if ($metodo === 'GET' && preg_match('#^/cnpj/(.+)$#', $uri, $matches)) {
    $cnpjInformado = urldecode($matches[1]);
    $resultado = $service->processarCnpj($cnpjInformado);

    http_response_code($resultado['status']);
    echo json_encode($resultado['resposta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Validar, consultar, salvar/atualizar e retornar via POST /cnpj
if ($metodo === 'POST' && $uri === '/cnpj') {
    $body = json_decode(file_get_contents('php://input'), true);
    $cnpjInformado = $body['cnpj'] ?? '';

    if (empty($cnpjInformado)) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Informe o campo "cnpj" no JSON.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resultado = $service->processarCnpj($cnpjInformado);
    http_response_code($resultado['status']);
    echo json_encode($resultado['resposta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. Listar empresas cadastradas com paginação e filtros (GET /empresas)
if ($metodo === 'GET' && $uri === '/empresas') {
    $resultado = $service->listarEmpresas($_GET);

    http_response_code($resultado['status']);
    echo json_encode($resultado['resposta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 4. Consultar dados completos da empresa por ID interno ou CNPJ (GET /empresas/{id_ou_cnpj})
if ($metodo === 'GET' && preg_match('#^/empresas/([^/]+)$#', $uri, $matches)) {
    $identificador = urldecode($matches[1]);
    $resultado = $service->buscarEmpresaPorIdOuCnpj($identificador);

    http_response_code($resultado['status']);
    echo json_encode($resultado['resposta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
echo json_encode([
    'sucesso' => false,
    'mensagem' => 'Endpoint não encontrado. Rotas disponíveis: GET /cnpj/{cnpj}, POST /cnpj, GET /empresas, GET /empresas/{id_ou_cnpj}'
], JSON_UNESCAPED_UNICODE);
