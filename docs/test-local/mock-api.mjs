// Faux /v1/payments de Virevo pour les tests locaux des modules.
// POST /v1/payments → { id, payment_url = return_url } ; FAIL=1 → 500.
import { createServer } from "node:http";

const fail = process.env.FAIL === "1";
createServer((req, res) => {
  let raw = "";
  req.on("data", (c) => (raw += c));
  req.on("end", () => {
    console.log(req.method, req.url, req.headers["idempotency-key"] ?? "", raw);
    if (fail) {
      res.writeHead(500, { "Content-Type": "application/json" }).end('{"error":"boom"}');
      return;
    }
    const body = raw ? JSON.parse(raw) : {};
    const out = req.url.endsWith("/refund")
      ? { id: "rf_demo", status: "REFUNDED" }
      : { id: `pay_demo_${body.reference}`, payment_url: body.return_url ?? "http://localhost:8089/" };
    res.writeHead(201, { "Content-Type": "application/json" }).end(JSON.stringify(out));
  });
}).listen(4010, () => console.log("mock sur 4010"));
