-- IMP-010 synthetic fixtures for the ISOLATED test database (primeclassy_testing).
--
-- These are invented values, never production data. The bcrypt hash below was produced by PHP
-- (password_hash('Secret123!', PASSWORD_BCRYPT)) so the Go service is verified against a REAL Laravel hash,
-- including the $2y$ prefix that PHP emits and Go's bcrypt must accept.
--
-- Applied by tools/parity/seed_fixtures.sh. Idempotent: it truncates the tables it owns first.

-- Foreign keys stay disabled for the whole script: fixture rows reference each other (a user's parent/agent
-- may appear later in the same statement, products reference their creator), so per-row FK validation would
-- reject valid-looking data. Re-enabled at the very end.
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM activity_logs;
DELETE FROM user_closures;
DELETE FROM users;
DELETE FROM roles;

DELETE FROM product_images;
DELETE FROM product_variation_compositions;
DELETE FROM product_variation_attribute_options;
DELETE FROM product_variation_attributes;
DELETE FROM product_variations;
DELETE FROM products;
DELETE FROM product_categories;

DELETE FROM cms_articles_translations;
DELETE FROM cms_articles;
DELETE FROM cms_pages_translations;
DELETE FROM cms_pages;
DELETE FROM cms_homepage_blocks;

DELETE FROM media;
DELETE FROM settings;
DELETE FROM agent_profiles;
DELETE FROM languages;
DELETE FROM provinces;
DELETE FROM regencies;
DELETE FROM districts;
DELETE FROM villages;

-- 11 locked roles (docs/05 §1)
INSERT INTO roles (id, slug, name, created_at, updated_at) VALUES
 (1,'super_admin','Super Admin',NOW(),NOW()),
 (2,'agen','Agen',NOW(),NOW()),
 (3,'admin','Admin',NOW(),NOW()),
 (4,'keuangan','Keuangan',NOW(),NOW()),
 (5,'korsal','Korsal',NOW(),NOW()),
 (6,'sales','Sales',NOW(),NOW()),
 (7,'sales-kurir-sub','Sales Kurir Sub',NOW(),NOW()),
 (8,'kurir','Kurir',NOW(),NOW()),
 (9,'koordinator-kurir','Koordinator Kurir',NOW(),NOW()),
 (10,'gudang','Gudang',NOW(),NOW()),
 (11,'konsumen','Konsumen',NOW(),NOW());

-- Users. Password for every fixture account is Secret123! (see the header).
INSERT INTO users (id, role_id, parent_id, agent_id, korsal_id, sales_id, name, email, phone, referral_code, status, password, created_at, updated_at) VALUES
 (1,1,NULL,10,NULL,NULL,'Super Admin','super@imp010.test','081100000001','SA-IMP010','active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (10,2,NULL,10,NULL,NULL,'Agen Satu','agen@imp010.test','081100000010','AG-IMP010','active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (11,3,10,10,NULL,NULL,'Admin Agen','admin@imp010.test','081100000011',NULL,'active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (12,4,10,10,NULL,NULL,'Keuangan Agen','keuangan@imp010.test','081100000012',NULL,'active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (13,11,10,10,NULL,NULL,'Konsumen Satu','konsumen@imp010.test','081100000013',NULL,'active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (14,6,10,10,13,14,'Sales Satu','sales@imp010.test','081100000014','SL-IMP010','active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (15,10,10,10,NULL,NULL,'Gudang Satu','gudang@imp010.test','081100000015',NULL,'active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (16,7,10,10,13,16,'Sub Seller','sub@imp010.test','081100000016','SS-IMP010','active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW()),
 (17,11,10,10,13,14,'Konsumen Sales','konsumen2@imp010.test','081100000017',NULL,'active','$2y$10$NC8qJYD6.cH2h4T0hcVLcembfGXh1fSplxbkU7sRk9eBLZgl3rVDq',NOW(),NOW());

INSERT INTO user_closures (ancestor_id, descendant_id, depth) VALUES
 (1,1,0),(10,10,0),(11,11,0),(12,12,0),(13,13,0),(14,14,0),(15,15,0),(16,16,0),
 (10,11,1),(10,12,1),(10,13,1),(10,14,1),(10,15,1),(10,16,1),(10,17,1),
 (1,10,1),(1,11,2),(1,12,2),(1,13,2),(1,14,2),(1,15,2),(1,16,2),(1,17,2);

INSERT INTO agent_profiles (id, user_id, store_name, address, latitude, longitude, phone, created_at, updated_at) VALUES
 (1,10,'Toko Prime Classy Imp010','Jl. Uji 1, Bandung',-6.9000000,107.6000000,'081100000010',NOW(),NOW());

-- Checkout eligibility fixtures (GET /checkout/steps, and later order creation).
--
-- Invented configuration covering every branch of ShippingQuoteService::activeProviders and
-- AvailablePaymentMethodService::availableMethods:
--   * rajaongkir and openroute globally active (an agent switch-off is applied per scenario by the harness),
--   * kmsit present but INACTIVE, so Kurir Toko is offered through ORS, never through the fallback here,
--   * bank_transfer/cod/down_payment selectable, plus an ACTIVE gateway row that must never appear.
DELETE FROM agent_shipping_provider_settings;
DELETE FROM agent_shipping_provider_configs;
DELETE FROM agent_payment_method_settings;
DELETE FROM shipping_providers;
DELETE FROM payment_methods;

INSERT INTO shipping_providers (id, code, name, is_active, created_at, updated_at) VALUES
 (1,'rajaongkir','RajaOngkir',1,NOW(),NOW()),
 (2,'openroute','OpenRoute',1,NOW(),NOW()),
 (3,'kmsit','KMSIT Routing Server',0,NOW(),NOW());

INSERT INTO payment_methods (id, code, name, type, is_active, created_at, updated_at) VALUES
 (1,'bank_transfer','Transfer Bank','manual',1,NOW(),NOW()),
 (2,'cod','Bayar di Tempat (COD)','cod',1,NOW(),NOW()),
 (3,'down_payment','DP / Down Payment','manual',1,NOW(),NOW()),
 (4,'gateway','Payment Gateway','gateway',1,NOW(),NOW());

-- Courier master list (Super Admin). RajaOngkir quoting intersects this list with the agent's own enabled
-- couriers, so both halves need a fixture.
DELETE FROM shipping_couriers;
INSERT INTO shipping_couriers (id, code, name, is_active, created_at, updated_at) VALUES
 (1,'jne','JNE',1,NOW(),NOW()),
 (2,'tiki','TIKI',1,NOW(),NOW()),
 (3,'pos','POS Indonesia',0,NOW(),NOW());

-- Checkout/order fixtures: a per-order admin fee, the voucher shapes the preview must price, and one address
-- book row so the address_id path is exercised (the inline path is exercised by the harness).
DELETE FROM konsumen_addresses;
INSERT INTO konsumen_addresses (id, user_id, label, recipient_name, phone, address_line,
  province_id, regency_id, district_id, village_id, latitude, longitude, is_default, created_at, updated_at) VALUES
 (1,13,'Rumah','Konsumen Satu','081100000013','Jl. Alamat Buku 1','32','3273','327301','32730101',-6.9000000,107.6000000,1,NOW(),NOW());

DELETE FROM vouchers;
INSERT INTO vouchers (id, agent_id, code, name, type, value, is_active, valid_from, valid_until,
  product_id, product_variation_id, max_uses, used_count, created_at, updated_at) VALUES
 (1,10,'HEMAT10K','Potongan 10 ribu','fixed',10000.00,1,NULL,NULL,NULL,NULL,100,0,NOW(),NOW()),
 (2,10,'PERSEN10','Diskon 10 persen','percentage',10.00,1,NULL,NULL,NULL,NULL,100,0,NOW(),NOW()),
 (3,10,'HANYA-LUMPUR','Khusus Kue Lumpur','fixed',5000.00,1,NULL,NULL,1,NULL,100,0,NOW(),NOW());
UPDATE settings SET `value` = '2500' WHERE `key` = 'checkout.admin_fee_amount';
INSERT INTO settings (id, `key`, value, `group`, is_public, created_at, updated_at)
 SELECT 5,'checkout.admin_fee_amount','2500','checkout',0,NOW(),NOW()
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'checkout.admin_fee_amount');

INSERT INTO languages (id, code, name, is_default, is_active, sort_order, created_at, updated_at) VALUES
 (1,'id','Indonesia',1,1,1,NOW(),NOW()),
 (2,'en','English',0,1,2,NOW(),NOW());

INSERT INTO settings (id, `key`, value, `group`, is_public, created_at, updated_at) VALUES
 (1,'site_title','Prime Classy Cake & Cookies','site',1,NOW(),NOW()),
 (2,'site_slogan','Kue untuk setiap momen','site',1,NOW(),NOW()),
 (3,'site_header','{"variant":"default"}','site',1,NOW(),NOW()),
 (4,'site_social','[]','site',1,NOW(),NOW());

-- Catalog: one simple product, one variation product, parent + child category.
INSERT INTO product_categories (id, parent_id, name, name_key, slug, is_active, sort_order, created_at, updated_at) VALUES
 (1,NULL,'Kue','kue','kue',1,1,NOW(),NOW()),
 (2,1,'Kue Basah','kue-basah','kue-basah',1,1,NOW(),NOW());

INSERT INTO products (id, category_id, created_by, name, slug, description, short_description, has_variations, base_price, weight_grams, status, sku, created_at, updated_at) VALUES
 (1,2,10,'Kue Lumpur','kue-lumpur','<p>Deskripsi kue lumpur</p>','Ringkas kue lumpur',0,45000.00,300,'active','SKU-LUMPUR','2026-11-10 10:00:00','2026-11-10 10:00:00'),
 (2,1,10,'Kue Ulang Tahun','kue-ulang-tahun','<p>Deskripsi kue ulang tahun</p>','Ringkas kue ulang tahun',1,NULL,NULL,'active',NULL,'2026-11-11 10:00:00','2026-11-11 10:00:00'),
 (3,1,10,'Kue Susu','kue-susu','<p>Deskripsi kue susu</p>','Ringkas kue susu',0,32000.00,250,'active','SKU-SUSU','2026-11-09 10:00:00','2026-11-09 10:00:00');

INSERT INTO product_variations (id, product_id, sku, price, weight_grams, is_active, sort_order, created_at, updated_at) VALUES
 (1,2,'SKU-ULT-20',250000.00,1500,1,1,NOW(),NOW()),
 (2,2,'SKU-ULT-24',350000.00,2000,1,2,NOW(),NOW());

INSERT INTO product_variation_attributes (id, product_id, name, sort_order, created_at, updated_at) VALUES
 (1,2,'Ukuran',1,NOW(),NOW());
INSERT INTO product_variation_attribute_options (id, product_variation_attribute_id, value, sort_order, created_at, updated_at) VALUES
 (1,1,'20 cm',1,NOW(),NOW()),
 (2,1,'24 cm',2,NOW(),NOW());
INSERT INTO product_variation_compositions (id, product_variation_id, product_variation_attribute_id, product_variation_attribute_option_id) VALUES
 (1,1,1,1),(2,2,1,2);

INSERT INTO product_images (id, product_id, product_variation_id, path, is_primary, sort_order, created_at, updated_at) VALUES
 (1,1,NULL,'products/kue-lumpur.jpg',1,1,NOW(),NOW()),
 (2,2,NULL,'products/kue-ult.jpg',1,1,NOW(),NOW());

-- Content: one published article (2 translations), one page, one homepage block.
INSERT INTO cms_articles (id, type, slug, cover_image_path, author_id, status, published_at, created_at, updated_at) VALUES
 (1,'article','tips-kue-imp010','articles/tips.jpg',1,'published','2026-11-01 00:00:00',NOW(),NOW());
INSERT INTO cms_articles_translations (id, cms_article_id, language_id, title, excerpt, body, created_at, updated_at) VALUES
 (1,1,1,'Tips Kue Imp010','Ringkasan tips','<p>Isi artikel</p>',NOW(),NOW()),
 (2,1,2,'Cake Tips Imp010','Summary','<p>Article body</p>',NOW(),NOW());

INSERT INTO cms_pages (id, slug, cover_image_path, status, published_at, created_at, updated_at) VALUES
 (1,'tentang-kami-imp010',NULL,'published','2026-11-01 00:00:00',NOW(),NOW());
INSERT INTO cms_pages_translations (id, cms_page_id, language_id, title, body, seo_title, seo_description, created_at, updated_at) VALUES
 (1,1,1,'Tentang Kami','<p>Tentang kami</p>','Tentang Kami','Deskripsi',NOW(),NOW());

INSERT INTO cms_homepage_blocks (id, type, content, sort_order, is_active, starts_at, ends_at, created_at, updated_at) VALUES
 (1,'product','{"heading":"Pilihan","product_ids":[1],"limit":4}',1,1,NULL,NULL,NOW(),NOW());

INSERT INTO media (id, disk, path, collection, original_filename, mime_type, extension, size, width, height, mediable_type, mediable_id, created_by, created_at, updated_at) VALUES
 (1,'public','site/logo.png','site_logo','logo.png','image/png','png',1024,100,100,NULL,NULL,1,NOW(),NOW());

INSERT INTO provinces (id, name) VALUES ('32','Jawa Barat');
INSERT INTO regencies (id, province_id, name, rajaongkir_city_id) VALUES ('3273','32','Kota Bandung',NULL);
INSERT INTO districts (id, regency_id, name) VALUES ('327301','3273','Sukajadi');
INSERT INTO villages (id, district_id, name) VALUES ('32730101','327301','Sukawarna');

SET FOREIGN_KEY_CHECKS = 1;
