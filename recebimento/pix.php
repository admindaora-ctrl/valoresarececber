<?php
// ============================================================
// PIX via BravoPay
// Base URL: https://bravopay.club/api/v1
// A chave é carregada do ambiente ou de um .env fora da pasta pública.
// ============================================================

function loadBravoPayEnvValue(string $name): ?string
{
    $key = getenv($name);
    if ($key !== false && trim($key) !== '') {
        return trim($key);
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
    $envPaths = array_unique([
        dirname(__DIR__) . '/.env',
        dirname($documentRoot) . '/.env',
        __DIR__ . '/.env',
    ]);

    foreach ($envPaths as $envPath) {
        if (!is_readable($envPath)) {
            continue;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = substr($line, 7);
            }

            if (preg_match('/^' . preg_quote($name, '/') . '\\s*=\\s*(.*)$/', $line, $matches)) {
                $value = trim($matches[1]);
                if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = preg_replace('/\\s+#.*$/', '', $value);
                }

                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {

    $API_KEY = loadBravoPayEnvValue('BRAVOPAY_API_TOKEN');
    $PRODUCT_ID = loadBravoPayEnvValue('BRAVOPAY_PRODUCT_ID');
    if (!$API_KEY) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['erro' => 'Chave da API BravoPay não configurada']);
        exit;
    }

    // ---- GERAR PIX ----
    if ($_POST['acao'] === 'gerar_pix') {

        $pix_chave = isset($_POST['pix'])      ? trim($_POST['pix'])      : '';
        $telefone = isset($_POST['telefone']) ? preg_replace('/\D/', '', $_POST['telefone']) : '';
        $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
        $nome     = $pix_chave ?: 'Cliente';
        $cpf      = isset($_POST['cpf']) ? preg_replace('/\D/', '', $_POST['cpf']) : '';

        // Identificador único para correlacionar esta cobrança.
        $identifier = 'svr_' . bin2hex(random_bytes(16));
        $customer = [
            'name'  => $nome,
            'email' => $email ?: 'cliente@svr.com',
        ];
        if ($telefone !== '') $customer['phone'] = $telefone;
        if (strlen($cpf) === 11) $customer['cpf'] = $cpf;

        $payload = [
            'amount_cents' => 4900,
            'method'       => 'pix',
            'customer'     => $customer,
            'description'  => 'Curso de Dança',
            'external_reference' => $identifier,
            'expires_in'   => 86400,
            'metadata'     => ['origem' => 'SVR', 'tipo' => 'curso_danca'],
        ];
        if ($PRODUCT_ID !== null && !preg_match('/^x+$/i', $PRODUCT_ID)) {
            $payload['product_id'] = $PRODUCT_ID;
        }

        $ch = curl_init('https://bravopay.club/api/v1/transactions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $API_KEY,
                'Idempotency-Key: ' . $identifier,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');

        $data = json_decode($response, true);

        if (($httpCode === 200 || $httpCode === 201) && is_array($data)) {
            // Mantém o formato esperado pelo JS atual; BravoPay fornece o PIX copia e cola.
            echo json_encode([
                'id'  => $data['id'] ?? '',
                'pix' => [
                    'qrcode'         => $data['pix']['copy_paste'] ?? $data['pix']['qr_code'] ?? '',
                    'base64'         => '',
                    'image'          => '',
                    'expirationDate' => $data['pix']['expires_at'] ?? date('c', strtotime('+1 day')),
                ]
            ]);
        } else {
            echo json_encode([
                'erro'    => 'Falha ao gerar PIX (HTTP ' . $httpCode . ')',
                'detalhe' => $data['error']['message'] ?? $data['message'] ?? $response
            ]);
        }
        exit;
    }

    // ---- VERIFICAR PAGAMENTO ----
    if ($_POST['acao'] === 'verificar_pagamento') {
        $transaction_id = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : '';

        if (!$transaction_id) {
            header('Content-Type: application/json');
            echo json_encode(['erro' => 'ID da transação não informado']);
            exit;
        }

        $url = 'https://bravopay.club/api/v1/transactions/' . rawurlencode($transaction_id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $API_KEY,
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        header('Content-Type: application/json');

        if ($httpCode === 200) {
            $data = json_decode($response, true);

            $statusOriginal = strtoupper($data['status'] ?? '');
            $statusJS = $statusOriginal === 'COMPLETED' ? 'PAID' : $statusOriginal;

            echo json_encode([
                'status'        => $statusJS,
                'statusRaw'     => $statusOriginal,
                'id'            => $data['id'] ?? '',
                'paymentMethod' => $data['method'] ?? '',
            ]);
        } else {
            $erro = json_decode($response, true);
            echo json_encode([
                'erro'    => 'Falha ao verificar pagamento (HTTP ' . $httpCode . ')',
                'detalhe' => $erro['error']['message'] ?? $erro['message'] ?? $response
            ]);
        }
        exit;
    }
}
?>
