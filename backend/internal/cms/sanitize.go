package cms

import (
	"regexp"
	"strings"
	"sync"

	"github.com/microcosm-cc/bluemonday"
)

var (
	htmlPolicyOnce  sync.Once
	htmlPolicy      *bluemonday.Policy
	unsafeHrefAttrs = []*regexp.Regexp{
		regexp.MustCompile(`(?i)\s+href\s*=\s*"\s*(?:javascript|vbscript|data):[^"]*"`),
		regexp.MustCompile(`(?i)\s+href\s*=\s*'\s*(?:javascript|vbscript|data):[^']*'`),
	}
	textAlignStyle   = regexp.MustCompile(`(?i)text-align:\s*(left|right|center|justify)\s*;?`)
	unsafeLinkAnchor = regexp.MustCompile(`(?i)<a\b[^>]*__primeclassy_unsafe_href__[^>]*>`)
	unsafeMarkerAttr = regexp.MustCompile(`(?i)\s+(?:href|title)="[^"]*__primeclassy_unsafe_href__[^"]*"`)
	unsafeNoFollow   = regexp.MustCompile(`(?i)\s+rel="nofollow"`)
	anchorTag        = regexp.MustCompile(`(?i)<a\b[^>]*>`)
	anchorRel        = regexp.MustCompile(`(?i)\s+rel=(?:"[^"]*"|'[^']*'|[^\s>]+)`)
	paragraphEnd     = regexp.MustCompile(`(?i)</p\s*>`)
	inlineTags       = regexp.MustCompile(`(?i)</?(strong|b|em|i|s|u|a|code)\b[^>]*>`)
	homepageIframe   = regexp.MustCompile(`(?i)</?iframe\b[^>]*>`)
	homepageVoidTag  = regexp.MustCompile(`(?i)<(img|br|hr|input)((?:[^>"']|"[^"]*")*)>`)
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
	// HTMLPurifier drops a disallowed link target but keeps the anchor and its text.
	// A safe temporary URL keeps bluemonday from dropping that otherwise empty anchor.
	for _, pattern := range unsafeHrefAttrs {
		input = pattern.ReplaceAllString(input, ` href="/__primeclassy_unsafe_href__" title="__primeclassy_unsafe_href__"`)
	}
	out := htmlPolicy.Sanitize(input)
	// Restore the harmless anchor shape after bluemonday has sanitized its contents.
	out = unsafeLinkAnchor.ReplaceAllStringFunc(out, func(anchor string) string {
		anchor = unsafeMarkerAttr.ReplaceAllString(anchor, "")
		anchor = unsafeNoFollow.ReplaceAllString(anchor, "")
		return anchor
	})
	// Match HTMLPurifier's canonical CSS spelling (`text-align:center;`).
	out = textAlignStyle.ReplaceAllStringFunc(out, func(value string) string {
		parts := textAlignStyle.FindStringSubmatch(value)
		if len(parts) != 2 {
			return value
		}
		return "text-align:" + strings.ToLower(parts[1]) + ";"
	})
	return out
}

// SanitizeHomepageBlockHTML matches the HTMLPurifier contract for custom
// homepage blocks while keeping the shared product/article sanitizer unchanged.
func SanitizeHomepageBlockHTML(input string) string {
	input = closeInlineAtParagraphEnd(input)
	// HTMLPurifier unwraps iframe tags and preserves their text content.
	input = homepageIframe.ReplaceAllString(input, "")
	out := SanitizeHTML(input)
	out = anchorTag.ReplaceAllStringFunc(out, func(anchor string) string {
		if regexp.MustCompile(`(?i)\btarget\s*=\s*(?:"_blank"|'_blank'|_blank)`).MatchString(anchor) {
			anchor = anchorRel.ReplaceAllString(anchor, "")
			return strings.TrimSuffix(anchor, ">") + ` rel="noreferrer noopener">`
		}
		return unsafeNoFollow.ReplaceAllString(anchor, "")
	})
	return homepageVoidTag.ReplaceAllString(out, `<$1$2 />`)
}

func closeInlineAtParagraphEnd(input string) string {
	indices := paragraphEnd.FindAllStringIndex(input, -1)
	if len(indices) == 0 {
		return input
	}
	var out strings.Builder
	last := 0
	for _, index := range indices {
		segment := input[last:index[0]]
		stack := []string{}
		for _, match := range inlineTags.FindAllStringSubmatch(segment, -1) {
			tag := strings.ToLower(strings.TrimLeft(match[0], "</"))
			tag = strings.FieldsFunc(tag, func(r rune) bool { return r == ' ' || r == '>' || r == '/' })[0]
			if strings.HasPrefix(match[0], "</") {
				for i := len(stack) - 1; i >= 0; i-- {
					if stack[i] == tag {
						stack = append(stack[:i], stack[i+1:]...)
						break
					}
				}
			} else {
				stack = append(stack, tag)
			}
		}
		out.WriteString(segment)
		for i := len(stack) - 1; i >= 0; i-- {
			out.WriteString("</" + stack[i] + ">")
		}
		out.WriteString(input[index[0]:index[1]])
		last = index[1]
	}
	out.WriteString(input[last:])
	return out.String()
}
