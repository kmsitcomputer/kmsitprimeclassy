package shipping

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
	"syscall"
)

// ErrBlockedEndpoint is the SSRF policy refusal (EndpointGuard). It is deliberately a plain error so the
// provider wrapper can turn it into the same generic 422 the reference produces, while the log keeps the reason.
var ErrBlockedEndpoint = errors.New("endpoint blocked by the SSRF policy")

// guard vets an agent-supplied routing endpoint (KMSIT/OSRM) exactly like App\Support\EndpointGuard:
//
//   - only http(s), no embedded credentials, no query/fragment;
//   - `localhost` and *.localhost are always refused;
//   - plain http is allowed ONLY for an operator-trusted host;
//   - every resolved address must be public (unless the host is trusted), and link-local / 169.254.0.0/16 /
//     0.0.0.0 / fe80:: / :: are refused even for a trusted host (cloud metadata protection);
//   - the vetted IP is PINNED for the request, so DNS cannot be re-answered between the check and the call.
//
// This is a security boundary, not a formality: without it an Agen could point `base_url` at 127.0.0.1:3306 or
// the cloud metadata service and have the server fetch it.
type vettedEndpoint struct {
	URL  string
	Host string
	Port int
	IP   string
}

func (g *guard) vet(raw string) (vettedEndpoint, error) {
	var out vettedEndpoint
	parsed, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || parsed.Host == "" || parsed.Scheme == "" {
		return out, fmt.Errorf("%w: invalid endpoint", ErrBlockedEndpoint)
	}
	scheme := strings.ToLower(parsed.Scheme)
	if scheme != "http" && scheme != "https" {
		return out, fmt.Errorf("%w: scheme must be http(s)", ErrBlockedEndpoint)
	}
	if parsed.User != nil {
		return out, fmt.Errorf("%w: credentials in endpoint", ErrBlockedEndpoint)
	}
	if parsed.RawQuery != "" || parsed.Fragment != "" {
		return out, fmt.Errorf("%w: query or fragment in endpoint", ErrBlockedEndpoint)
	}

	host := strings.ToLower(strings.Trim(parsed.Hostname(), "[]"))
	trusted := g.isTrusted(host)
	if !trusted && (host == "localhost" || strings.HasSuffix(host, ".localhost")) {
		return out, fmt.Errorf("%w: loopback host", ErrBlockedEndpoint)
	}
	if scheme == "http" && !trusted {
		return out, fmt.Errorf("%w: plain http requires a trusted host", ErrBlockedEndpoint)
	}

	port := 0
	if p := parsed.Port(); p != "" {
		port, err = strconv.Atoi(p)
		if err != nil || port < 1 || port > 65535 {
			return out, fmt.Errorf("%w: invalid port", ErrBlockedEndpoint)
		}
	} else if scheme == "https" {
		port = 443
	} else {
		port = 80
	}

	ips, err := g.resolve(host)
	if err != nil || len(ips) == 0 {
		return out, fmt.Errorf("%w: host does not resolve", ErrBlockedEndpoint)
	}
	for _, ip := range ips {
		netIP := net.ParseIP(ip)
		if netIP == nil {
			return out, fmt.Errorf("%w: unparseable address", ErrBlockedEndpoint)
		}
		if !trusted && !isPublicIP(netIP) {
			return out, fmt.Errorf("%w: non-public address", ErrBlockedEndpoint)
		}
		if isAlwaysBlocked(netIP) {
			return out, fmt.Errorf("%w: blocked address range", ErrBlockedEndpoint)
		}
	}

	base := scheme + "://" + parsed.Host
	if parsed.Port() != "" {
		base = scheme + "://" + net.JoinHostPort(parsed.Hostname(), strconv.Itoa(port))
	}
	out = vettedEndpoint{
		URL:  base + strings.TrimRight(parsed.Path, "/"),
		Host: host,
		Port: port,
		IP:   ips[0],
	}
	return out, nil
}

// dialer pins the vetted IP so the HTTP client cannot be re-resolved to a different (internal) address.
func (v vettedEndpoint) dialer() func(ctx context.Context, network, addr string) (net.Conn, error) {
	return func(ctx context.Context, network, _ string) (net.Conn, error) {
		d := &net.Dialer{Timeout: 4 * timeSecond, Control: func(_, _ string, c syscall.RawConn) error { return nil }}
		return d.DialContext(ctx, network, net.JoinHostPort(v.IP, strconv.Itoa(v.Port)))
	}
}

// isTrusted is the operator allow-list (services.kmsit.trusted_hosts). An Agen can never add to it.
func (g *guard) isTrusted(host string) bool {
	for _, h := range g.trusted {
		if strings.EqualFold(strings.TrimSpace(h), host) {
			return true
		}
	}
	return false
}

func (g *guard) resolve(host string) ([]string, error) {
	if ip := net.ParseIP(host); ip != nil {
		return []string{host}, nil
	}
	lookup := g.lookup
	if lookup == nil {
		lookup = net.LookupIP
	}
	addrs, err := lookup(host)
	if err != nil {
		return nil, err
	}
	out := make([]string, 0, len(addrs))
	for _, a := range addrs {
		out = append(out, a.String())
	}
	return out, nil
}

func isPublicIP(ip net.IP) bool {
	return !(ip.IsPrivate() || ip.IsLoopback() || ip.IsUnspecified() || ip.IsLinkLocalUnicast() ||
		ip.IsLinkLocalMulticast() || ip.IsInterfaceLocalMulticast() || ip.IsMulticast())
}

// isAlwaysBlocked refuses cloud metadata / link-local / unspecified even for a trusted host.
func isAlwaysBlocked(ip net.IP) bool {
	if ip4 := ip.To4(); ip4 != nil {
		return (ip4[0] == 169 && ip4[1] == 254) || ip4[0] == 0
	}
	if ip.IsLinkLocalUnicast() || ip.IsUnspecified() {
		return true
	}
	// IPv4-mapped IPv6 (::ffff:a.b.c.d) is evaluated as the embedded IPv4 address.
	if v4 := ip.To16(); v4 != nil && len(v4) == 16 {
		allZeroPrefix := true
		for i := 0; i < 10; i++ {
			if v4[i] != 0 {
				allZeroPrefix = false
				break
			}
		}
		if allZeroPrefix && v4[10] == 0xff && v4[11] == 0xff {
			return isAlwaysBlocked(net.IP(v4[12:]))
		}
	}
	return false
}
