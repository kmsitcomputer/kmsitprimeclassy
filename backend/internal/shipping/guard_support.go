package shipping

import (
	"net"
	"regexp"
)

// guard is the EndpointGuard policy state: the operator allow-list plus a DNS seam for tests.
type guard struct {
	trusted []string
	lookup  func(string) ([]net.IP, error)
}

func mustRegexp(pattern string) *regexp.Regexp { return regexp.MustCompile(pattern) }
