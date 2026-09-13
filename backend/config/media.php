<?php

/**
 * Per-collection upload rules for App\Services\Media\MediaService. Adding a
 * new image slot anywhere in the dashboard is a new entry here, never a new
 * bespoke upload path — see the service's own docblock for why.
 */
return [

    'disk' => env('MEDIA_DISK', 'public'),

    'directory' => 'media',

    'collections' => [

        'cms_article_cover' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        'cms_page_cover' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        // Images inserted inline by CKEditor 5's own upload adapter — no
        // fixed owner at upload time (see the migration's mediable_* docblock).
        'cms_content' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        'product_image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        'homepage_block' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 2 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        // Deliberately no SVG here — an SVG can carry an embedded <script>,
        // exactly the "file used to run a malicious script" risk the
        // Blueprint calls out; every image collection stays raster-only so
        // MediaService's getimagesize() decode check actually means something.
        'site_logo' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 2 * 1024 * 1024,
            'max_width' => 2000,
            'max_height' => 2000,
        ],

        'site_favicon' => [
            'mimes' => ['image/png'],
            'extensions' => ['png'],
            'max_size' => 512 * 1024,
            'max_width' => 512,
            'max_height' => 512,
        ],

        // "Kurir harus memasukan bukti pengiriman" — the delivery photo required to mark a shipment 'terkirim'.
        'shipment_proof' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        // "Konsumen dapat memasukan bukti pembayaran COD" — the photo a konsumen submits, pending Admin/Agen confirmation.
        'cod_payment_proof' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
        ],

        // Profile photo — the one collection every authenticated role may
        // upload to (see MediaPolicy::create), never just super_admin.
        'user_avatar' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 2 * 1024 * 1024,
            'max_width' => 2000,
            'max_height' => 2000,
        ],

    ],

];
