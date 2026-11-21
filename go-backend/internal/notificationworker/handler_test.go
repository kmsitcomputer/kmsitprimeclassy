package notificationworker

import "testing"

func TestSMTPFailureMatchesLaravelUnexpectedResponse(t *testing.T) {
	want := `Expected response code "250/251/252" but got code "550", with message "550 5.1.1 test recipient rejected".`
	for _, detail := range []string{"550 5.1.1 test recipient rejected", `550 "5.1.1 test recipient rejected"`} {
		class, message := smtpFailure(detail)
		if class != "UnexpectedResponseException" || message != want {
			t.Fatalf("smtpFailure(%q) = (%q, %q), want (%q, %q)", detail, class, message, "UnexpectedResponseException", want)
		}
	}
}

func TestSMTPFailureKeepsTransportErrors(t *testing.T) {
	class, message := smtpFailure("dial tcp: connection refused")
	if class != "TransportException" || message != "dial tcp: connection refused" {
		t.Fatalf("smtpFailure() = (%q, %q), want TransportException with original detail", class, message)
	}
}
