import crypto from "node:crypto";
import pg from "pg";

const { Client } = pg;

const RANDOM_PIX_KEY_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/;
const REQUESTED_PIX_KEY = "e4d1468b-41dd-40b1-8bbb-86825c3958c7";
const ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";

if (process.env.VERCEL_ENV !== "production") {
  throw new Error("Refusing reset outside Vercel production.");
}

const email = (process.env.NEXORA_SUPER_ADMIN_EMAIL || "admin@nexora.local").trim().toLowerCase();
const cpf = (process.env.NEXORA_SUPER_ADMIN_CPF || "00000000000").replace(/\D+/g, "");
const password = process.env.NEXORA_SUPER_ADMIN_PASSWORD || "";

if (!email || password.length < 8 || !isValidCpf(cpf)) {
  throw new Error("Refusing reset because super admin bootstrap is not configured.");
}
if (!RANDOM_PIX_KEY_PATTERN.test(REQUESTED_PIX_KEY)) {
  throw new Error("Refusing reset because the requested Pix key is invalid.");
}

const client = new Client(databaseConfig());

await client.connect();
try {
  const usersColumns = await columnSet(client, "users");
  const now = Date.now();
  const userId = crypto.randomUUID();
  const inviteCode = randomCode(8);
  const user = {
    id: userId,
    public_id: `NX-${randomCode(8)}`,
    name: "Fundador Nexora",
    email,
    email_verified: true,
    verification_code_hash: null,
    verification_expires_at: null,
    password_reset_code_hash: null,
    password_reset_expires_at: null,
    cpf_hash: hmac(`cpf:${cpf}`),
    cpf_cipher: encrypt(cpf),
    birthdate: null,
    pix_cipher: encrypt(REQUESTED_PIX_KEY),
    password_hash: hashPassword(password),
    status: "APPROVED",
    role: "SUPER_ADMIN",
    xp: 0,
    level: 1,
    buff_bps: 0,
    on_time_returned_cents: 0,
    early_returned_cents: 0,
    invited_by: null,
    invite_code: inviteCode,
    created_at_ms: now,
    admin_fee_due_cents: 0,
  };

  await client.query("BEGIN");
  for (const table of ["auth_tokens", "pix_receipts", "contributions", "support_requests", "audit_logs", "users"]) {
    if (await tableExists(client, table)) {
      await client.query(`DELETE FROM ${table}`);
    }
  }

  await insertObject(client, "users", user, usersColumns);

  if (await tableExists(client, "audit_logs")) {
    await insertObject(client, "audit_logs", {
      id: crypto.randomUUID(),
      actor_user_id: userId,
      action: "PRODUCTION_DATABASE_RESET",
      target: userId,
      created_at_ms: now,
    }, await columnSet(client, "audit_logs"));
  }

  await client.query("COMMIT");

  const { rows } = await client.query("SELECT COUNT(*)::int AS total_users FROM users");
  console.log("NEXORA_PRODUCTION_RESET", JSON.stringify({
    ok: true,
    role: "SUPER_ADMIN",
    status: "APPROVED",
    totalUsers: rows[0]?.total_users ?? null,
    pixMatchesRequestedKey: true,
    superAdminEmailHash: crypto.createHash("sha256").update(email).digest("hex"),
  }));
} catch (error) {
  await client.query("ROLLBACK").catch(() => {});
  throw error;
} finally {
  await client.end();
}

function databaseConfig() {
  const connectionString = process.env.DATABASE_URL || process.env.POSTGRES_URL || process.env.DB_URL;
  if (connectionString) {
    return {
      connectionString,
      ssl: { rejectUnauthorized: false },
    };
  }

  return {
    host: process.env.DB_HOST || process.env.PGHOST || process.env.POSTGRES_HOST,
    port: Number(process.env.DB_PORT || process.env.PGPORT || process.env.POSTGRES_PORT || 5432),
    database: process.env.DB_DATABASE || process.env.PGDATABASE || process.env.POSTGRES_DATABASE,
    user: process.env.DB_USERNAME || process.env.PGUSER || process.env.POSTGRES_USER,
    password: process.env.DB_PASSWORD || process.env.PGPASSWORD || process.env.POSTGRES_PASSWORD,
    ssl: { rejectUnauthorized: false },
  };
}

async function tableExists(db, table) {
  const { rows } = await db.query("SELECT to_regclass($1) AS table_name", [`public.${table}`]);
  return rows[0]?.table_name !== null;
}

async function columnSet(db, table) {
  const { rows } = await db.query(
    "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1",
    [table],
  );
  return new Set(rows.map((row) => row.column_name));
}

async function insertObject(db, table, data, allowedColumns) {
  const entries = Object.entries(data).filter(([column]) => allowedColumns.has(column));
  const columns = entries.map(([column]) => column);
  const values = entries.map(([, value]) => value);
  const placeholders = values.map((_, index) => `$${index + 1}`);
  await db.query(
    `INSERT INTO ${table} (${columns.join(", ")}) VALUES (${placeholders.join(", ")})`,
    values,
  );
}

function isValidCpf(value) {
  if (!/^\d{11}$/.test(value) || /^(\d)\1{10}$/.test(value)) {
    return false;
  }

  const digits = value.split("").map(Number);
  for (let length = 9; length <= 10; length += 1) {
    let sum = 0;
    for (let index = 0; index < length; index += 1) {
      sum += digits[index] * ((length + 1) - index);
    }
    const check = (sum * 10) % 11;
    if ((check === 10 ? 0 : check) !== digits[length]) {
      return false;
    }
  }

  return true;
}

function hashPassword(value) {
  const salt = crypto.randomBytes(16);
  const iterations = 210000;
  const hash = crypto.pbkdf2Sync(value, salt, iterations, 32, "sha256");
  return ["pbkdf2_sha256", String(iterations), base64Url(salt), base64Url(hash)].join("$");
}

function encrypt(value) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv("aes-256-gcm", dataKey(), iv);
  const ciphertext = Buffer.concat([cipher.update(value, "utf8"), cipher.final()]);
  const tag = cipher.getAuthTag();
  return `${base64Url(iv)}.${base64Url(Buffer.concat([ciphertext, tag]))}`;
}

function hmac(message) {
  return base64Url(crypto.createHmac("sha256", cpfPepper()).update(message).digest());
}

function dataKey() {
  const raw = process.env.NEXORA_DATA_KEY_B64;
  let key = raw
    ? Buffer.from(raw, "base64")
    : crypto.createHash("sha256").update("nexora-local-dev-data-key-change-before-production").digest();

  if (![16, 24, 32].includes(key.length)) {
    throw new Error("NEXORA_DATA_KEY_B64 invalida.");
  }
  if (key.length !== 32) {
    key = crypto.createHash("sha256").update(key).digest();
  }
  return key;
}

function cpfPepper() {
  return process.env.NEXORA_CPF_PEPPER || "nexora-local-dev-cpf-pepper-change-before-production";
}

function base64Url(bytes) {
  return Buffer.from(bytes).toString("base64").replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
}

function randomCode(size) {
  let result = "";
  for (let index = 0; index < size; index += 1) {
    result += ALPHABET[crypto.randomInt(0, ALPHABET.length)];
  }
  return result;
}
