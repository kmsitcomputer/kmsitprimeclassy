package config

import (
	"bufio"
	"os"
	"strings"
)

// FileGetter returns a getter that resolves a key from a `.env`-style file first and the process environment
// second — the same precedence shape Laravel's Dotenv has for a local run (the real environment wins nowhere,
// because in production nothing is read from a file: only the explicit env vars are set).
//
// The parser is deliberately minimal and predictable: `KEY=VALUE` per line, `#`/`;` comment lines, optional
// surrounding single or double quotes, no interpolation and no `export` prefix. Anything it does not
// understand is ignored rather than guessed, so a malformed file cannot silently change a production value.
//
// A missing file is not an error: the service then relies purely on the real environment.
func FileGetter(path string) func(string) string {
	values := map[string]string{}

	if fh, err := os.Open(path); err == nil {
		defer func() { _ = fh.Close() }()
		scanner := bufio.NewScanner(fh)
		// A long DB_PASSWORD or APP_KEY is still far below this; the bigger buffer just avoids a surprise.
		scanner.Buffer(make([]byte, 0, 64*1024), 1<<20)
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
				continue
			}
			line = strings.TrimPrefix(line, "export ")
			eq := strings.IndexByte(line, '=')
			if eq <= 0 {
				continue
			}
			key := strings.TrimSpace(line[:eq])
			val := strings.TrimSpace(line[eq+1:])
			if len(val) >= 2 {
				if (val[0] == '"' && val[len(val)-1] == '"') || (val[0] == '\'' && val[len(val)-1] == '\'') {
					val = val[1 : len(val)-1]
				}
			}
			values[key] = val
		}
	}

	return func(key string) string {
		// An explicit process environment variable always wins over the file: it is how a container or a
		// supervisord unit overrides a default without editing a file.
		if v, ok := os.LookupEnv(key); ok {
			return v
		}
		return values[key]
	}
}
