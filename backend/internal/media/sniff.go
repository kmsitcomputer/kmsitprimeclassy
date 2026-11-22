package media

import "net/http"

// httpSniff is the standard content sniffer, kept as a variable so tests can inject a deterministic one.
var httpSniff = http.DetectContentType
