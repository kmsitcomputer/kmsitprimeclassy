package admininfra

import (
	"context"
	"crypto/tls"
	"fmt"
	"io"
	"mime"
	"net"
	"net/mail"
	"net/smtp"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/cryptox"
)

// CheckSMTP connects, negotiates encryption and authenticates using the saved relay settings.
func (s *Service) CheckSMTP(ctx context.Context) (bool, string, error) {
	settings, err := s.settings(ctx, "integration.smtp")
	if err != nil {
		return false, "", err
	}
	client, _, _, _, username, password, err := s.openSMTP(ctx, settings)
	if err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	if err := client.Quit(); err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	return true, "", nil
}

// SendSMTPTest always uses the authenticated actor's stored email as the only recipient.
// SendAccountMail sends one account mail (e.g. the registration welcome) through the
// dashboard SMTP when it is ON and complete — the runtime equivalent of
// SmtpSettings::applyRuntime pointing Laravel's default mailer at the dashboard
// configuration. With SMTP off/incomplete it is a silent no-op, exactly like the
// reference's `log` mailer: nothing is transmitted and nothing observable changes.
func (s *Service) SendAccountMail(ctx context.Context, recipient, subject, body string) (bool, string, error) {
	if _, err := mail.ParseAddress(recipient); err != nil || strings.ContainsAny(recipient, "\r\n") {
		return false, "invalid_recipient", nil
	}
	settings, err := s.settings(ctx, "integration.smtp")
	if err != nil {
		return false, "", err
	}
	if !smtpTruthy(settings["smtp.enabled"]) || settings["smtp.host"] == "" ||
		settings["smtp.port"] == "" || settings["smtp.from_address"] == "" {
		return false, "smtp_disabled", nil // log-mailer equivalent: silent no-op
	}
	client, _, from, fromName, username, password, err := s.openSMTP(ctx, settings)
	if err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	defer client.Close()
	if err = client.Mail(from); err == nil {
		err = client.Rcpt(recipient)
	}
	var data io.WriteCloser
	if err == nil {
		data, err = client.Data()
	}
	if err == nil {
		fromHeader := (&mail.Address{Name: fromName, Address: from}).String()
		toHeader := (&mail.Address{Address: recipient}).String()
		message := fmt.Sprintf("From: %s\r\nTo: %s\r\nSubject: %s\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n%s\r\n",
			fromHeader, toHeader, mime.QEncoding.Encode("UTF-8", cleanSMTPHeader(subject)), body)
		_, err = io.WriteString(data, message)
		closeErr := data.Close()
		if err == nil {
			err = closeErr
		}
	}
	if err == nil {
		err = client.Quit()
	}
	if err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	return true, "", nil
}

func smtpTruthy(value string) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "on", "yes":
		return true
	}
	return false
}

func (s *Service) SendSMTPTest(ctx context.Context, recipient, subject, body string) (bool, string, error) {
	if _, err := mail.ParseAddress(recipient); err != nil || strings.ContainsAny(recipient, "\r\n") {
		return false, "config_incomplete", nil
	}
	settings, err := s.settings(ctx, "integration.smtp")
	if err != nil {
		return false, "", err
	}
	client, _, from, fromName, username, password, err := s.openSMTP(ctx, settings)
	if err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	defer client.Close()
	if err = client.Mail(from); err == nil {
		err = client.Rcpt(recipient)
	}
	var data io.WriteCloser
	if err == nil {
		data, err = client.Data()
	}
	if err == nil {
		fromHeader := (&mail.Address{Name: fromName, Address: from}).String()
		toHeader := (&mail.Address{Address: recipient}).String()
		message := fmt.Sprintf("From: %s\r\nTo: %s\r\nSubject: %s\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n%s\r\n", fromHeader, toHeader, mime.QEncoding.Encode("UTF-8", cleanSMTPHeader(subject)), body)
		_, err = io.WriteString(data, message)
		closeErr := data.Close()
		if err == nil {
			err = closeErr
		}
	}
	if err == nil {
		err = client.Quit()
	}
	if err != nil {
		return false, redactSMTPError(err.Error(), username, password), nil
	}
	return true, "", nil
}

func (s *Service) openSMTP(ctx context.Context, settings map[string]string) (*smtp.Client, string, string, string, string, string, error) {
	host, port := settings["smtp.host"], settings["smtp.port"]
	from, fromName, username := settings["smtp.from_address"], settings["smtp.from_name"], settings["smtp.username"]
	password := smtpSecret(s.appKey, settings, "smtp.password")
	if host == "" || port == "" || from == "" {
		return nil, host, from, fromName, username, password, fmt.Errorf("config_incomplete")
	}
	if settings["smtp.password"] != "" && password == "" {
		return nil, host, from, fromName, username, "", fmt.Errorf("invalid encrypted SMTP password")
	}
	timeout := intDefault(settings["smtp.timeout"], 10)
	if timeout < 2 {
		timeout = 2
	}
	if timeout > 60 {
		timeout = 60
	}
	conn, err := (&net.Dialer{Timeout: time.Duration(timeout) * time.Second}).DialContext(ctx, "tcp", net.JoinHostPort(host, port))
	if err != nil {
		return nil, host, from, fromName, username, password, err
	}
	_ = conn.SetDeadline(time.Now().Add(time.Duration(timeout) * time.Second))
	mode := settings["smtp.encryption"]
	if mode != "ssl" && mode != "none" {
		mode = "tls"
	}
	if mode == "ssl" {
		tlsConn := tls.Client(conn, &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12})
		if err = tlsConn.HandshakeContext(ctx); err != nil {
			conn.Close()
			return nil, host, from, fromName, username, password, err
		}
		conn = tlsConn
	}
	client, err := smtp.NewClient(conn, host)
	if err != nil {
		conn.Close()
		return nil, host, from, fromName, username, password, err
	}
	if mode == "tls" || mode == "" {
		ok, _ := client.Extension("STARTTLS")
		if !ok {
			client.Close()
			return nil, host, from, fromName, username, password, fmt.Errorf("STARTTLS is required but unavailable")
		}
		if err = client.StartTLS(&tls.Config{ServerName: host, MinVersion: tls.VersionTLS12}); err != nil {
			client.Close()
			return nil, host, from, fromName, username, password, err
		}
	}
	if username != "" {
		ok, mechanisms := client.Extension("AUTH")
		if !ok {
			client.Close()
			return nil, host, from, fromName, username, password, fmt.Errorf("SMTP AUTH is not supported")
		}
		var auth smtp.Auth
		for _, mechanism := range strings.Fields(mechanisms) {
			switch strings.ToUpper(mechanism) {
			case "CRAM-MD5":
				auth = smtp.CRAMMD5Auth(username, password)
			case "PLAIN":
				auth = smtp.PlainAuth("", username, password, host)
			case "LOGIN":
				auth = loginAuth{username, password}
			}
			if auth != nil {
				break
			}
		}
		if auth == nil {
			client.Close()
			return nil, host, from, fromName, username, password, fmt.Errorf("SMTP AUTH mechanism is unsupported")
		}
		if err = client.Auth(auth); err != nil {
			client.Close()
			return nil, host, from, fromName, username, password, err
		}
	}
	return client, host, from, fromName, username, password, nil
}

type loginAuth struct{ username, password string }

func (a loginAuth) Start(*smtp.ServerInfo) (string, []byte, error) { return "LOGIN", []byte{}, nil }
func (a loginAuth) Next(challenge []byte, more bool) ([]byte, error) {
	if !more {
		return nil, nil
	}
	switch strings.ToLower(strings.TrimSpace(string(challenge))) {
	case "username:":
		return []byte(a.username), nil
	case "password:":
		return []byte(a.password), nil
	default:
		return nil, fmt.Errorf("unexpected SMTP AUTH LOGIN challenge")
	}
}

func smtpSecret(key string, settings map[string]string, name string) string {
	cipher := settings[name]
	if cipher == "" {
		return ""
	}
	value, err := cryptox.DecryptString(key, cipher)
	if err != nil {
		return ""
	}
	return value
}

func redactSMTPError(message string, secrets ...string) string {
	for _, secret := range secrets {
		if secret != "" {
			message = strings.ReplaceAll(message, secret, "[hidden]")
		}
	}
	message = strings.Join(strings.Fields(message), " ")
	runes := []rune(message)
	if len(runes) > 300 {
		message = string(runes[:300])
	}
	return message
}

func cleanSMTPHeader(value string) string {
	return strings.ReplaceAll(strings.ReplaceAll(value, "\r", ""), "\n", "")
}
