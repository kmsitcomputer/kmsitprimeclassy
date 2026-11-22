package cms

import (
	"strings"
	"testing"
)

func TestSanitizeHTMLKeepsEditorMarkupAndRemovesActiveContent(t *testing.T) {
	input := `<h2 style="text-align:center;color:red" onclick="alert(1)">News</h2>` +
		`<script>alert(1)</script><p><a href="javascript:alert(1)" target="_blank">bad</a>` +
		`<a href="https://example.test" target="_blank">safe</a><img src="https://example.test/a.png" onerror="x"></p>`
	got := SanitizeHTML(input)
	for _, forbidden := range []string{"<script", "onclick", "onerror", "javascript:", "color:red"} {
		if strings.Contains(strings.ToLower(got), forbidden) {
			t.Errorf("sanitized HTML retains %q: %s", forbidden, got)
		}
	}
	for _, allowed := range []string{"<h2", "text-align:center;", "News", `href="https://example.test"`, "<img"} {
		if !strings.Contains(got, allowed) {
			t.Errorf("sanitized HTML removed allowed content %q: %s", allowed, got)
		}
	}
}

func TestSanitizeHTMLKeepsAnchorWhenUnsafeHrefIsRemoved(t *testing.T) {
	got := SanitizeHTML(`<p>safe <a href='javascript:alert(1)'>link</a></p>`)
	want := `<p>safe <a>link</a></p>`
	if got != want {
		t.Fatalf("SanitizeHTML() = %q, want %q", got, want)
	}
}

func TestValidateSafeLinkRejectsExecutableAndProtocolRelativeURLs(t *testing.T) {
	for _, raw := range []string{"javascript:alert(1)", "data:text/html,x", "//example.test/path", "/\\example.test"} {
		if ValidateSafeLink(raw) {
			t.Errorf("ValidateSafeLink(%q) = true; want false", raw)
		}
	}
	for _, raw := range []string{"https://example.test/a", "mailto:help@example.test", "tel:+628123456789", "/produk/roti", "#kontak"} {
		if !ValidateSafeLink(raw) {
			t.Errorf("ValidateSafeLink(%q) = false; want true", raw)
		}
	}
}

func TestCustomHomepageBlockSanitizesStoredHTML(t *testing.T) {
	content := map[string]any{"html": `<p>safe</p><iframe src="https://evil.test"></iframe>`}
	if err := sanitizeAndValidateBlock("custom", content); err != nil {
		t.Fatal(err)
	}
	html := content["html"].(string)
	if !strings.Contains(html, "safe") || strings.Contains(html, "iframe") {
		t.Fatalf("custom block HTML was not safely sanitized: %s", html)
	}
}

func TestHomepageBlockSanitizerMatchesPurifierParagraphAndLinkOutput(t *testing.T) {
	got := SanitizeHomepageBlockHTML(`<p><strong>one</p><p>two<!--secret--></p><p><a href="https://example.test" target="_blank">safe</a><a href="mailto:help@example.test">mail</a></p>`)
	want := `<p><strong>one</strong></p><p>two</p><p><a href="https://example.test" target="_blank" rel="noreferrer noopener">safe</a><a href="mailto:help@example.test">mail</a></p>`
	if got != want {
		t.Fatalf("SanitizeHomepageBlockHTML() = %q, want %q", got, want)
	}
	if got := SanitizeHomepageBlockHTML(`<iframe>kept text</iframe><script>removed text</script>`); got != `kept text` {
		t.Fatalf("SanitizeHomepageBlockHTML() active content = %q, want %q", got, `kept text`)
	}
	if got := SanitizeHomepageBlockHTML(`<img src="https://example.test/a.png">`); got != `<img src="https://example.test/a.png" />` {
		t.Fatalf("SanitizeHomepageBlockHTML() image = %q", got)
	}
}
