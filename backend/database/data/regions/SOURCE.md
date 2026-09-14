# Region data source

**Source**: [cahyadsn/wilayah](https://github.com/cahyadsn/wilayah) (MIT licensed), `db/wilayah.sql`.

**Basis**: Kepmendagri (Keputusan Menteri Dalam Negeri) No 300.2.2-2138 Tahun 2025 — the
official Indonesian administrative region code decree. This is the same community-maintained
mirror of the government's own code list used widely across Indonesian civic/logistics
tooling; it is not AI-generated and not hand-typed.

**Downloaded**: 2026-09-13, from `https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql`
(commit reflected by that file at download time — re-download the same URL to check for
upstream updates; the repo's own `change_log.md` documents what changed between Kepmendagri
revisions).

**Row counts at import** (verified against the source file, not assumed):

| Level | Count |
|---|---|
| Provinces | 38 |
| Regencies (Kabupaten/Kota) | 514 |
| Districts (Kecamatan) | 7,265 |
| Villages (Kelurahan/Desa) | 83,345 |

## Transform

The source ships one table (`wilayah(kode, nama)`) with a dotted hierarchical code
(e.g. `32.73.01.1001`) whose segment count (1/2/3/4) indicates the level. `convert.py`
(not shipped — a one-off transform script) split this into the four CSVs in this
directory, concatenating each row's own code with its ancestors' (`32`+`73`+`01`+`1001`
-> province `32`, regency `3273`, district `327301`, village `3273011001`) — this
produces exactly the 2/4/6/10-digit codes this project's `provinces`/`regencies`/
`districts`/`villages` tables already use as their primary keys (see the
`2026_09_08_090000_create_region_tables` migration), so no schema change was needed.

## Files

- `provinces.csv` — `code,name`
- `regencies.csv` — `code,province_code,name,rajaongkir_city_id` (the last column is
  always blank in this dataset — see below)
- `districts.csv` — `code,regency_code,name`
- `villages.csv` — `code,district_code,name`

Region **type** (Kabupaten vs Kota, etc.) is preserved inside the `name` string itself
(e.g. `"Kabupaten Aceh Selatan"` vs `"Kota Bandung"`), matching this project's existing
convention — no separate `type` column exists or was added.

## RajaOngkir mapping — NOT included, on purpose

This dataset has **no relationship to RajaOngkir's own city numbering** — RajaOngkir uses
its own internal, unrelated city IDs, resolvable only by calling RajaOngkir's own API with
a real account (`GET /starter|basic|pro/city`, see `RajaOngkirProvider`). This project has
no live RajaOngkir credentials configured, so `regencies.rajaongkir_city_id` is imported as
`NULL` for every row here — never a guessed or fabricated value.

Populating it requires either:

1. A Super Admin with a real RajaOngkir account calling its `/city` endpoint and matching
   results to `regencies.name` (city/regency names generally match closely, but always
   verify — RajaOngkir's own names occasionally abbreviate or spell differently), then
   saving the mapping via the existing CSV admin importer
   (`RegionImportExportController`/`RegionCsvService`, columns already include
   `rajaongkir_city_id`); or
2. A future one-time sync command built against that live API once credentials exist.

`regions:import` (this dataset's importer) never overwrites an already-populated
`rajaongkir_city_id` on re-run — see `RegionImporter`'s docblock.
