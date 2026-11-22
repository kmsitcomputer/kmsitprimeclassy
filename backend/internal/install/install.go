package install

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"runtime"
	"strings"
)

type Requirement struct {
	Label string `json:"label"`
	OK    bool   `json:"ok"`
}

type Requirements struct {
	Go      Requirement `json:"go"`
	EnvFile Requirement `json:"env_file"`
	AllOK   bool        `json:"all_ok"`
}

func CheckRequirements(envPath string) Requirements {
	goReq := Requirement{Label: runtime.Version(), OK: true}
	info, err := os.Stat(envPath)
	envOK := err == nil && !info.IsDir()
	if os.IsNotExist(err) {
		dirOK := false
		if dir, dirErr := os.Open("."); dirErr == nil {
			dirOK = true
			_ = dir.Close()
		}
		envOK = dirOK
	}
	envReq := Requirement{Label: envPath, OK: envOK}
	return Requirements{Go: goReq, EnvFile: envReq, AllOK: goReq.OK && envReq.OK}
}

func WriteEnv(path string, values map[string]string) error {
	if path == "" {
		return errors.New("env path is empty")
	}
	lines := []string{}
	seen := map[string]bool{}
	if file, err := os.Open(path); err == nil {
		scanner := bufio.NewScanner(file)
		for scanner.Scan() {
			line := scanner.Text()
			key := strings.SplitN(line, "=", 2)[0]
			key = strings.TrimSpace(key)
			if value, ok := values[key]; ok {
				lines = append(lines, key+"="+value)
				seen[key] = true
				continue
			}
			lines = append(lines, line)
		}
		if err := scanner.Err(); err != nil {
			_ = file.Close()
			return err
		}
		_ = file.Close()
	}
	for key, value := range values {
		if !seen[key] {
			lines = append(lines, key+"="+value)
		}
	}
	content := strings.Join(lines, "\n")
	if content != "" {
		content += "\n"
	}
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		return fmt.Errorf("write env: %w", err)
	}
	return nil
}
