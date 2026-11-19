package cms

import (
	"regexp"
	"sync"

	"github.com/microcosm-cc/bluemonday"
)

var (
	htmlPolicyOnce sync.Once
	htmlPolicy     *bluemonday.Policy
)

// SanitizeHTML follows HtmlSanitizerService's allowlist. It deliberately does not
// use bluemonday's broader UGC policy: CMS HTML only permits the CKEditor tags,
// attributes, links and text-align styling accepted by the Laravel purifier.
func SanitizeHTML(input string) string {
	htmlPolicyOnce.Do(func() {
		p := bluemonday.NewPolicy()
		p.AllowElements(
			"p", "br", "strong", "b", "em", "i", "s", "u",
			"h1", "h2", "h3", "h4", "ul", "ol", "li", "a", "img",
			"blockquote", "code", "pre",
		)
		p.AllowAttrs("style").OnElements("p", "h1", "h2", "h3", "h4")
		p.AllowStyles("text-align").
			Matching(regexp.MustCompile(`(?i)^(left|right|center|justify)$`)).
			OnElements("p", "h1", "h2", "h3", "h4")
		p.AllowAttrs("href", "title", "target", "rel").OnElements("a")
		p.AllowAttrs("src", "alt", "width", "height").OnElements("img")
		p.AllowURLSchemes("http", "https", "mailto")
		p.AllowRelativeURLs(true)
		p.RequireParseableURLs(true)
		p.RequireNoFollowOnLinks(true)
		p.AddTargetBlankToFullyQualifiedLinks(true)
		htmlPolicy = p
	})
	return htmlPolicy.Sanitize(input)
}
