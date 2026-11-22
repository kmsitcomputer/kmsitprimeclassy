package media

import (
	"bytes"
	"image"
	"image/color"
	"image/png"
	"testing"
)

// pngBytes builds a tiny REAL png so the decode check passes — a fixture with valid header bytes but a broken
// body is what separates the decode check from the MIME sniff.
func pngBytes(t *testing.T, w, h int) []byte {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for x := 0; x < w; x++ {
		for y := 0; y < h; y++ {
			img.Set(x, y, color.RGBA{R: 0x40, G: 0x80, B: 0xC0, A: 0xFF})
		}
	}
	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func TestValidateAcceptsRealImages(t *testing.T) {
	for _, collection := range []string{"fulfillment_handover_proof", "user_avatar", "site_favicon"} {
		if collection == "site_favicon" {
			continue
		}
		ext, err := Validate(collection, Upload{Filename: "proof.jpg", Content: pngBytes(t, 10, 10)})
		if err != nil {
			t.Fatalf("%s: %v", collection, err)
		}
		if ext != "jpg" {
			t.Errorf("extension = %q, want the client's own jpg", ext)
		}
	}
}

func TestValidateRejectsForgedContent(t *testing.T) {
	// PHP code renamed to .jpg: the MIME sniff must catch it before anything else.
	content := []byte("<?php echo 'pwn'; ?>")
	if _, err := Validate("fulfillment_handover_proof", Upload{Filename: "evil.jpg", Content: content}); err == nil {
		t.Fatal("a PHP payload renamed to .jpg was accepted")
	}

	// Valid PNG magic bytes followed by garbage: the DECODE check must catch it.
	forged := append([]byte{0x89, 'P', 'N', 'G', 0x0D, 0x0A, 0x1A, 0x0A}, []byte("not really a png")...)
	if _, err := Validate("fulfillment_handover_proof", Upload{Filename: "forged.png", Content: forged}); err == nil {
		t.Fatal("forged header bytes passed the decode check")
	}
}

func TestValidateRejectsWrongCollection(t *testing.T) {
	// site_favicon allows PNG only.
	if _, err := Validate("site_favicon", Upload{Filename: "logo.jpg", Content: pngBytes(t, 8, 8)}); err == nil {
		t.Fatal("a jpg was accepted for the png-only favicon collection")
	}
	if _, err := Validate("site_favicon", Upload{Filename: "logo.png", Content: pngBytes(t, 600, 600)}); err == nil {
		t.Fatal("an oversized-dimension favicon was accepted")
	}
}

func TestValidateRejectsUnknownCollection(t *testing.T) {
	if _, err := Validate("nope", Upload{Filename: "x.jpg", Content: pngBytes(t, 2, 2)}); err == nil {
		t.Fatal("an unknown collection must be refused")
	}
}

func TestUUIDShape(t *testing.T) {
	v, err := randomUUID()
	if err != nil {
		t.Fatal(err)
	}
	if len(v) != 36 || v[8] != '-' || v[13] != '-' || v[14] != '4' {
		t.Errorf("uuid = %q, want a v4 shape", v)
	}
}
