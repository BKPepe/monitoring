package security

import (
	"regexp"
)

var (
	// Regex patterns for redacting sensitive flags, tokens, passwords, keys in process command lines
	passwordFlagRegex  = regexp.MustCompile(`(?i)(--?p(?:ass(?:word)?)?|--?secret|--?token|--?api-?key|--?auth)(?:=|\s*)(\S+)`)
	bearerTokenRegex   = regexp.MustCompile(`(?i)(bearer\s+)[a-zA-Z0-9_\-\.~+\/=]+`)
	inlineKVRegex      = regexp.MustCompile(`(?i)(password|passwd|secret|api_key|apikey|access_token|auth_token)=([^\s&]+)`)
	connStringPassword = regexp.MustCompile(`(?i)(:[^:@/]+)@`)
)

// SanitizeCommandString redacts sensitive credentials from a process command line or log line.
func SanitizeCommandString(input string) string {
	if input == "" {
		return ""
	}

	result := passwordFlagRegex.ReplaceAllString(input, "${1}=[REDACTED]")
	result = bearerTokenRegex.ReplaceAllString(result, "${1}[REDACTED]")
	result = inlineKVRegex.ReplaceAllString(result, "${1}=[REDACTED]")
	result = connStringPassword.ReplaceAllString(result, ":[REDACTED]@")

	return result
}

// SanitizeProcessList iterates over process maps or structs and redacts command/name fields for privacy.
func SanitizeProcessList(processes []any) []any {
	if len(processes) == 0 {
		return processes
	}

	sanitized := make([]any, 0, len(processes))
	for _, proc := range processes {
		switch p := proc.(type) {
		case map[string]any:
			procMap := make(map[string]any, len(p))
			for k, v := range p {
				if strVal, ok := v.(string); ok && (k == "name" || k == "cmd" || k == "command" || k == "args" || k == "user") {
					procMap[k] = SanitizeCommandString(strVal)
				} else {
					procMap[k] = v
				}
			}
			sanitized = append(sanitized, procMap)
		case string:
			sanitized = append(sanitized, SanitizeCommandString(p))
		default:
			sanitized = append(sanitized, proc)
		}
	}

	return sanitized
}
