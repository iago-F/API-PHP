<?php

require_once __DIR__ . '/Database.php';

class CnpjService
{
    public function processarCnpj(string $cnpjInformado): array
    {
        // 1. Remover caracteres de formatação
        $cnpjLimpo = preg_replace('/\D/', '', $cnpjInformado);

        // 2. Validar o CNPJ informado
        if (!$this->validarDigitos($cnpjLimpo)) {
            return [
                'status' => 422,
                'resposta' => [
                    'sucesso' => false,
                    'valido' => false,
                    'cnpj' => $cnpjLimpo,
                    'mensagem' => 'CNPJ inválido (falha na verificação dos dígitos).'
                ]
            ];
        }

        // 3. Consultar serviço externo (BrasilAPI)
        $consulta = $this->consultarBrasilApi($cnpjLimpo);
        if (!$consulta['sucesso']) {
            return [
                'status' => $consulta['status'],
                'resposta' => [
                    'sucesso' => false,
                    'valido' => false,
                    'cnpj' => $cnpjLimpo,
                    'mensagem' => $consulta['mensagem']
                ]
            ];
        }

        $dadosBrutos = $consulta['dados'];

        // 4. Estruturar os dados retornados
        $dadosEstruturados = $this->estruturarDados($dadosBrutos);

        // 5. Persistir ou atualizar no banco local (evita duplicidade)
        $acao = $this->salvarNoBanco($dadosEstruturados, $dadosBrutos);

        // 6. Retornar os dados processados em JSON
        return [
            'status' => 200,
            'resposta' => [
                'sucesso' => true,
                'operacao' => $acao,
                'mensagem' => $acao === 'atualizado'
                    ? 'CNPJ já existente: dados cadastrais atualizados com sucesso.'
                    : 'CNPJ validado, estruturado e cadastrado com sucesso.',
                'empresa' => $dadosEstruturados
            ]
        ];
    }

    public function listarEmpresas(array $params): array
    {
        $pdo = Database::getConnection();

        // Controle de paginação
        $pagina = max(1, (int)($params['pagina'] ?? 1));
        $porPagina = (int)($params['por_pagina'] ?? 10);
        if ($porPagina < 1) {
            $porPagina = 10;
        } elseif ($porPagina > 100) {
            $porPagina = 100;
        }
        $offset = ($pagina - 1) * $porPagina;

        // Filtros opcionais: uf, municipio, situacao_cadastral
        $where = [];
        $bindings = [];

        if (!empty($params['uf'])) {
            $where[] = "UPPER(uf) = UPPER(:uf)";
            $bindings[':uf'] = trim($params['uf']);
        }

        if (!empty($params['municipio'])) {
            $where[] = "UPPER(municipio) LIKE UPPER(:municipio)";
            $bindings[':municipio'] = '%' . trim($params['municipio']) . '%';
        }

        if (!empty($params['situacao_cadastral'])) {
            $where[] = "UPPER(situacao_cadastral) = UPPER(:situacao_cadastral)";
            $bindings[':situacao_cadastral'] = trim($params['situacao_cadastral']);
        }

        $sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total de registros filtrados
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM empresas {$sqlWhere}");
        $stmtTotal->execute($bindings);
        $totalRegistros = (int)$stmtTotal->fetchColumn();
        $totalPaginas = $totalRegistros > 0 ? (int)ceil($totalRegistros / $porPagina) : 0;

        // Consulta paginada
        $sqlDados = "
            SELECT 
                cnpj, razao_social, nome_fantasia, situacao_cadastral, 
                data_abertura, cnae_principal, logradouro, numero, 
                bairro, municipio, uf, cep, valido, atualizado_em
            FROM empresas
            {$sqlWhere}
            ORDER BY atualizado_em DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmtDados = $pdo->prepare($sqlDados);
        foreach ($bindings as $chave => $valor) {
            $stmtDados->bindValue($chave, $valor);
        }
        $stmtDados->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $stmtDados->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtDados->execute();

        $empresas = $stmtDados->fetchAll();

        foreach ($empresas as &$emp) {
            $emp['valido'] = (bool)$emp['valido'];
        }

        return [
            'status' => 200,
            'resposta' => [
                'sucesso' => true,
                'paginacao' => [
                    'pagina_atual' => $pagina,
                    'por_pagina' => $porPagina,
                    'total_registros' => $totalRegistros,
                    'total_paginas' => $totalPaginas
                ],
                'filtros_aplicados' => [
                    'uf' => $params['uf'] ?? null,
                    'municipio' => $params['municipio'] ?? null,
                    'situacao_cadastral' => $params['situacao_cadastral'] ?? null
                ],
                'dados' => $empresas
            ]
        ];
    }

    private function consultarBrasilApi(string $cnpj): array
    {
        $url = 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);

        $resposta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($erroCurl) {
            return ['sucesso' => false, 'status' => 502, 'mensagem' => 'Erro de rede ao consultar serviço externo: ' . $erroCurl];
        }

        $dados = json_decode($resposta, true);

        if ($httpCode === 404) {
            return ['sucesso' => false, 'status' => 404, 'mensagem' => 'CNPJ não encontrado na base da Receita Federal.'];
        }

        if ($httpCode !== 200 || !is_array($dados)) {
            return ['sucesso' => false, 'status' => 502, 'mensagem' => $dados['message'] ?? 'Falha ao obter dados do serviço externo.'];
        }

        return ['sucesso' => true, 'status' => 200, 'dados' => $dados];
    }

    private function estruturarDados(array $dados): array
    {
        $situacao = $dados['descricao_situacao_cadastral'] ?? 'DESCONHECIDA';

        return [
            'cnpj' => $dados['cnpj'] ?? '',
            'valido' => ($situacao === 'ATIVA'),
            'razao_social' => $dados['razao_social'] ?? '',
            'nome_fantasia' => $dados['nome_fantasia'] ?: null,
            'situacao_cadastral' => $situacao,
            'data_abertura' => $dados['data_inicio_atividade'] ?? null,
            'cnae_principal' => [
                'codigo' => $dados['cnae_fiscal'] ?? null,
                'descricao' => $dados['cnae_fiscal_descricao'] ?? null,
            ],
            'endereco' => [
                'logradouro' => trim(($dados['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($dados['logradouro'] ?? '')),
                'numero' => $dados['numero'] ?? '',
                'bairro' => $dados['bairro'] ?? '',
                'municipio' => $dados['municipio'] ?? '',
                'uf' => $dados['uf'] ?? '',
                'cep' => $dados['cep'] ?? ''
            ]
        ];
    }

    private function salvarNoBanco(array $empresa, array $dadosBrutos): string
    {
        $pdo = Database::getConnection();

        $check = $pdo->prepare("SELECT 1 FROM empresas WHERE cnpj = :cnpj");
        $check->execute([":cnpj" => $empresa["cnpj"]]);
        $jaExiste = (bool)$check->fetchColumn();

        $sql = "
            INSERT INTO empresas (
                cnpj, razao_social, nome_fantasia, situacao_cadastral, data_abertura,
                cnae_principal, logradouro, numero, bairro, municipio, uf, cep,
                valido, payload_completo, atualizado_em
            ) VALUES (
                :cnpj, :razao_social, :nome_fantasia, :situacao_cadastral, :data_abertura,
                :cnae_principal, :logradouro, :numero, :bairro, :municipio, :uf, :cep,
                :valido, :payload_completo, CURRENT_TIMESTAMP
            )
            ON CONFLICT(cnpj) DO UPDATE SET
                razao_social = excluded.razao_social,
                nome_fantasia = excluded.nome_fantasia,
                situacao_cadastral = excluded.situacao_cadastral,
                data_abertura = excluded.data_abertura,
                cnae_principal = excluded.cnae_principal,
                logradouro = excluded.logradouro,
                numero = excluded.numero,
                bairro = excluded.bairro,
                municipio = excluded.municipio,
                uf = excluded.uf,
                cep = excluded.cep,
                valido = excluded.valido,
                payload_completo = excluded.payload_completo,
                atualizado_em = CURRENT_TIMESTAMP
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ":cnpj" => $empresa["cnpj"],
            ":razao_social" => $empresa["razao_social"],
            ":nome_fantasia" => $empresa["nome_fantasia"],
            ":situacao_cadastral" => $empresa["situacao_cadastral"],
            ":data_abertura" => $empresa["data_abertura"],
            ":cnae_principal" => $empresa["cnae_principal"]["descricao"],
            ":logradouro" => $empresa["endereco"]["logradouro"],
            ":numero" => $empresa["endereco"]["numero"],
            ":bairro" => $empresa["endereco"]["bairro"],
            ":municipio" => $empresa["endereco"]["municipio"],
            ":uf" => $empresa["endereco"]["uf"],
            ":cep" => $empresa["endereco"]["cep"],
            ":valido" => $empresa["valido"] ? 1 : 0,
            ":payload_completo" => json_encode($dadosBrutos, JSON_UNESCAPED_UNICODE)
        ]);

        return $jaExiste ? "atualizado" : "criado";
    }

      


    public function buscarEmpresaPorIdOuCnpj(string $identificador): array
    {
        $pdo = Database::getConnection();
        $limpo = preg_replace('/\D/', '', $identificador);

        if ($limpo === '') {
            return [
                'status' => 400,
                'resposta' => [
                    'sucesso' => false,
                    'mensagem' => 'Informe um ID numérico ou CNPJ válido.'
                ]
            ];
        }

        // Se tiver 14 dígitos, busca pelo CNPJ; caso contrário, busca pelo ID interno
        if (strlen($limpo) === 14) {
            $stmt = $pdo->prepare("SELECT * FROM empresas WHERE cnpj = :valor LIMIT 1");
            $stmt->bindValue(':valor', $limpo, PDO::PARAM_STR);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :valor LIMIT 1");
            $stmt->bindValue(':valor', (int)$limpo, PDO::PARAM_INT);
        }

        $stmt->execute();
        $registro = $stmt->fetch();

        if (!$registro) {
            return [
                'status' => 404,
                'resposta' => [
                    'sucesso' => false,
                    'mensagem' => 'Empresa não encontrada no banco de dados local.'
                ]
            ];
        }

        // Decodifica o JSON completo salvo originalmente da Receita/BrasilAPI
        $dadosCompletos = json_decode($registro['payload_completo'] ?? '{}', true);

        return [
            'status' => 200,
            'resposta' => [
                'sucesso' => true,
                'registro_interno' => [
                    'id' => (int)$registro['id'],
                    'cnpj' => $registro['cnpj'],
                    'valido' => (bool)$registro['valido'],
                    'atualizado_em' => $registro['atualizado_em']
                ],
                'dados_completos' => $dadosCompletos
            ]
        ];
    }



    private function validarDigitos(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match("/^(\d)\1{13}$/", $cnpj)) {
            return false;
        }

        for ($t = 12; $t < 14; $t++) {
            $soma = 0;
            $pos = $t - 7;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$cnpj[$i] * $pos--;
                if ($pos < 2) {
                    $pos = 9;
                }
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int)$cnpj[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }
}