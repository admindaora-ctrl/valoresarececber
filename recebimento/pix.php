<?php
// ============================================================
// PIX via PayForge — versão robusta (sempre retorna JSON)
// ============================================================

// Garante que QUALQUER saída seja JSON, mesmo em fatal error
ob_start();
header('Content-Type: application/json; charset=utf-8');

function responder($arr, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// Captura erro fatal e devolve JSON em vez de HTML
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erro' => 'Erro interno PHP', 'detalhe' => $err['message']]);
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['acao'])) {
    responder(['erro' => 'Requisição inválida (use POST com campo "acao")'], 400);
}

$PUBLIC_KEY = 'admindaora_2hckdpdxe8h2kwnf';
$SECRET_KEY = '64bt6dte5vb8izgxac7xeepyuntgyxm7osppgndvt69zkkaute1g53x3qrs63igr';

// ---------------- GERAR PIX ----------------
if ($_POST['acao'] === 'gerar_pix') {

    $pix_chave = isset($_POST['pix'])      ? trim($_POST['pix'])      : '';
    $telefone  = isset($_POST['telefone']) ? preg_replace('/\D/', '', $_POST['telefone']) : '';
    $email     = isset($_POST['email'])    ? trim($_POST['email'])    : '';
    $cpf       = isset($_POST['cpf'])      ? preg_replace('/\D/', '', $_POST['cpf']) : '';
    $nome      = $pix_chave ?: 'Cliente SVR';

    if (strlen($telefone) < 10) $telefone = '11999999999';
    if (strlen($cpf) !== 11)    $cpf = '00000000000';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'cliente@svr.com';

    $payload = [
        'identifier' => 'svr_' . uniqid(),
        'amount'     => 25.00,
        'client'     => [
            'name'     => $nome,
            'email'    => $email,
            'phone'    => $telefone,
            'document' => $cpf,
        ],
        'products' => [[
            'id'       => 'EBOOK001',
            'name'     => 'Ebook Emagrecimento',
            'quantity' => 1,
            'price'    => 25.00,
        ]],
        'dueDate'  => date('Y-m-d', strtotime('+1 day')),
        'metadata' => ['origem' => 'SVR', 'tipo' => 'ebook'],
    ];

    $ch = curl_init('https://app.payforge.me/api/v1/gateway/pix/receive');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-public-key: ' . $PUBLIC_KEY,
            'x-secret-key: ' . $SECRET_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false, // evita falha SSL em XAMPP/localhost
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        responder(['erro' => 'Falha de conexão com PayForge', 'detalhe' => $curlErr], 502);
    }

    $data = json_decode($response, true);

    if ($httpCode === 200 || $httpCode === 201) {
        responder([
            'id'  => $data['transactionId'] ?? $data['id'] ?? '',
            'pix' => [
                'qrcode'         => $data['pix']['code']      ?? '',
                'base64'         => $data['pix']['base64']    ?? '',
                'image'          => $data['pix']['image']     ?? '',
                'expirationDate' => $data['pix']['expiresAt'] ?? date('c', strtotime('+1 day')),
            ],
        ]);
    }

    responder([
        'erro'    => 'Falha ao gerar PIX (HTTP ' . $httpCode . ')',
        'detalhe' => $data['message'] ?? $data['error'] ?? $response,
    ], $httpCode ?: 500);
}

// ---------------- VERIFICAR PAGAMENTO ----------------
if ($_POST['acao'] === 'verificar_pagamento') {
    $transaction_id = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : '';
    if (!$transaction_id) responder(['erro' => 'ID da transação não informado'], 400);

    $url = 'https://app.payforge.me/api/v1/gateway/transactions?id=' . urlencode($transaction_id);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'x-public-key: ' . $PUBLIC_KEY,
            'x-secret-key: ' . $SECRET_KEY,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        responder(['erro' => 'Falha de conexão', 'detalhe' => $curlErr], 502);
    }

    $data = json_decode($response, true);

    if ($httpCode === 200) {
        $map = ['COMPLETED'=>'PAID','PENDING'=>'PENDING','FAILED'=>'FAILED','REFUNDED'=>'REFUNDED'];
        $statusOriginal = strtoupper($data['status'] ?? '');
        responder([
            'status'        => $map[$statusOriginal] ?? $statusOriginal,
            'statusRaw'     => $statusOriginal,
            'id'            => $data['id'] ?? '',
            'paymentMethod' => $data['paymentMethod'] ?? '',
        ]);
    }

    responder([
        'erro'    => 'Falha ao verificar pagamento (HTTP ' . $httpCode . ')',
        'detalhe' => $data['message'] ?? $response,
    ], $httpCode ?: 500);
}

responder(['erro' => 'Ação desconhecida: ' . $_POST['acao']], 400);
