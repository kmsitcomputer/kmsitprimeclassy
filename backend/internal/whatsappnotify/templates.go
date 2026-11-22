package whatsappnotify

import (
	"regexp"
	"strings"
	"unicode/utf8"
)

// templates.go ports MessageTemplates: safe {token} rendering with a per-event
// allow-list. A template line whose OPTIONAL variable is unavailable is DROPPED;
// a missing REQUIRED variable aborts the render (nil).

var tokenRe = regexp.MustCompile(`\{([a-z_]+)\}`)
var controlRe = regexp.MustCompile(`[\x00-\x1F\x7F]+`)
var multiNewlineRe = regexp.MustCompile(`\n{3,}`)

// Tokens returns the template's tokens without braces.
func Tokens(template string) []string {
	seen := map[string]bool{}
	var out []string
	for _, m := range tokenRe.FindAllStringSubmatch(template, -1) {
		if !seen[m[1]] {
			seen[m[1]] = true
			out = append(out, m[1])
		}
	}
	return out
}

// Render ports MessageTemplates::render. Returns ("", false) when a required
// variable is unavailable.
func Render(event, template string, data map[string]*string) (string, bool) {
	def, ok := events[event]
	if !ok {
		return "", false
	}
	for _, required := range def.Required {
		if Clean(data[required]) == nil {
			return "", false
		}
	}
	var lines []string
	for _, line := range regexp.MustCompile(`\r\n|\r|\n`).Split(template, -1) {
		drop := false
		rendered := tokenRe.ReplaceAllStringFunc(line, func(tok string) string {
			name := tok[1 : len(tok)-1]
			value := Clean(data[name])
			if value == nil {
				if name == "nama_pelanggan" {
					return "Pelanggan"
				}
				drop = true
				return ""
			}
			return *value
		})
		if !drop {
			lines = append(lines, rendered)
		}
	}
	text := strings.TrimSpace(multiNewlineRe.ReplaceAllString(strings.Join(lines, "\n"), "\n\n"))
	if text == "" {
		return "", false
	}
	return text, true
}

// Clean ports MessageTemplates::clean: trim, strip control chars, neutralise
// braces (no token injection), cap at 200 runes. nil = unavailable.
func Clean(value *string) *string {
	if value == nil {
		return nil
	}
	s := strings.TrimSpace(*value)
	if s == "" {
		return nil
	}
	s = controlRe.ReplaceAllString(s, " ")
	s = strings.NewReplacer("{", "(", "}", ")").Replace(s)
	if utf8.RuneCountInString(s) > 200 {
		s = string([]rune(s)[:200])
	}
	return &s
}

// Str is a small helper for building var maps from computed strings.
func Str(v string) *string { return &v }

// Strp maps a nullable string to *string (nil stays nil).
func Strp(v *string) *string { return v }
