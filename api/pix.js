// ============================================================
// PIX via PayForge — Vercel Serverless Function (Node.js)
// Equivalente ao pix.php original. Aceita FormData OU JSON.
// ============================================================

export const config = { runtime: "nodejs" };

const PAYFORGE_BASE = "https://app.payforge.me/api/v1/gateway";

// Lê body como texto bruto (Vercel não faz parse automático em todos os casos)
async function readRawBody(req) {
  return await new Promise((resolve, reject) => {
    let data = "";
    req.on("data", (chunk) => (data += chunk));
    req.on("end", () => resolve(data));
    req.on("error", reject);
  });
}

// Parse simples de multipart/form-data (apenas campos texto)
function parseMultipart(raw, boundary) {
  const obj = {};
  const parts = raw.split(`--${boundary}`);
  for (const part of parts) {
    const match = part.match(/name="([^"]+)"\r?\n\r?\n([\s\S]*?)\r?\n?$/);
    if (match) obj[match[1]] = match[2].replace(/\r?\n--$/, "").trim();
  }
  return obj;
}

// Faz parse de JSON, x-www-form-urlencoded OU multipart/form-data
async function parseBody(req) {
  const ctype = (req.headers["content-type"] || "").toLowerCase();
  const raw = await readRawBody(req);

  if (ctype.includes("application/json")) {
    try { return JSON.parse(raw || "{}"); } catch { return {}; }
  }
  if (ctype.includes("multipart/form-data")) {
    const m = ctype.match(/boundary=(.+)$/);
    if (m) return parseMultipart(raw, m[1].trim());
  }
  const params = new URLSearchParams(raw);
  const obj = {};
  for (const [k, v] of params.entries()) obj[k] = v;
  return obj;
}

function json(res, status, payload) {
  res.statusCode = status;
  res.setHeader("Content-Type", "application/json; charset=utf-8");
  res.end(JSON.stringify(payload));
}

export default async function handler(req, res) {
  try {
    if (req.method !== "POST") {
      return json(res, 400, { erro: 'Requisição inválida (use POST com campo "acao")' });
    }

    const body = await parseBody(req);
    const acao = body.acao;

    const PUBLIC_KEY = process.env.PAYFORGE_PUBLIC_KEY;
    const SECRET_KEY = process.env.PAYFORGE_SECRET_KEY;

    if (!PUBLIC_KEY || !SECRET_KEY) {
      return json(res, 500, { erro: "Chaves PayForge não configuradas no servidor" });
    }

    const headers = {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "x-public-key": PUBLIC_KEY,
      "x-secret-key": SECRET_KEY,
    };

    // ---------------- GERAR PIX ----------------
    if (acao === "gerar_pix") {
      const pixChave = (body.pix || "").trim();
      let telefone = (body.telefone || "").replace(/\D/g, "");
      let email = (body.email || "").trim();
      let cpf = (body.cpf || "").replace(/\D/g, "");
      const nome = pixChave || "Cliente SVR";

      if (telefone.length < 10) telefone = "11999999999";
      if (cpf.length !== 11) cpf = "00000000000";
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) email = "cliente@svr.com";

      const dueDate = new Date(Date.now() + 24 * 60 * 60 * 1000)
        .toISOString()
        .slice(0, 10);

      const payload = {
        identifier: "svr_" + Date.now().toString(36) + Math.random().toString(36).slice(2, 8),
        amount: 25.0,
        client: { name: nome, email, phone: telefone, document: cpf },
        products: [{ id: "EBOOK001", name: "Ebook Emagrecimento", quantity: 1, price: 25.0 }],
        dueDate,
        metadata: { origem: "SVR", tipo: "ebook" },
      };

      const r = await fetch(`${PAYFORGE_BASE}/pix/receive`, {
        method: "POST",
        headers,
        body: JSON.stringify(payload),
      });

      const text = await r.text();
      let data = {};
      try { data = JSON.parse(text); } catch {}

      if (r.status === 200 || r.status === 201) {
        return json(res, 200, {
          id: data.transactionId || data.id || "",
          pix: {
            qrcode: data?.pix?.code || "",
            base64: data?.pix?.base64 || "",
            image: data?.pix?.image || "",
            expirationDate: data?.pix?.expiresAt || new Date(Date.now() + 86400000).toISOString(),
          },
        });
      }

      return json(res, r.status || 500, {
        erro: `Falha ao gerar PIX (HTTP ${r.status})`,
        detalhe: data.message || data.error || text,
      });
    }

    // ---------------- VERIFICAR PAGAMENTO ----------------
    if (acao === "verificar_pagamento") {
      const transactionId = (body.transaction_id || "").trim();
      if (!transactionId) return json(res, 400, { erro: "ID da transação não informado" });

      const url = `${PAYFORGE_BASE}/transactions?id=${encodeURIComponent(transactionId)}`;
      const r = await fetch(url, { method: "GET", headers });

      const text = await r.text();
      let data = {};
      try { data = JSON.parse(text); } catch {}

      if (r.status === 200) {
        const map = { COMPLETED: "PAID", PENDING: "PENDING", FAILED: "FAILED", REFUNDED: "REFUNDED" };
        const statusOriginal = String(data.status || "").toUpperCase();
        return json(res, 200, {
          status: map[statusOriginal] || statusOriginal,
          statusRaw: statusOriginal,
          id: data.id || "",
          paymentMethod: data.paymentMethod || "",
        });
      }

      return json(res, r.status || 500, {
        erro: `Falha ao verificar pagamento (HTTP ${r.status})`,
        detalhe: data.message || text,
      });
    }

    return json(res, 400, { erro: "Ação desconhecida: " + acao });
  } catch (err) {
    return json(res, 500, { erro: "Erro interno", detalhe: String(err?.message || err) });
  }
}
