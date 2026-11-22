-- Hits for simple rate limits (for now: loading the links of a multilink).
-- key_hash is an HMAC of the IP address, never the address itself. Old rows are removed on every hit.
CREATE TABLE rate_hits (
    bucket   TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    hit_at   TEXT NOT NULL
);
CREATE INDEX idx_rate_hits_key ON rate_hits (bucket, key_hash, hit_at);
CREATE INDEX idx_rate_hits_time ON rate_hits (hit_at);
