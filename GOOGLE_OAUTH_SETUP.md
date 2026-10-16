# Google OAuth and Calendar Setup

Google Calendar is used by Demo Bookings to create Google Meet links.

## 1. Create Google credentials

In Google Cloud Console:

1. Create or select a project.
2. Enable the **Google Calendar API**.
3. Configure the OAuth consent screen.
4. Create an OAuth client ID for a **Web application**.
5. Add this exact authorized redirect URI for the current ngrok tunnel:

```text
https://unhappily-disarm-stubbly.ngrok-free.dev/auth/google-callback.php
```

The URI must match character-for-character, including HTTPS, path, and trailing details. A new ngrok URL requires a new Google Console redirect URI.

## 2. Set `.env`

```env
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://unhappily-disarm-stubbly.ngrok-free.dev/auth/google-callback.php
```

Restart Apache after changing `.env` so the PHP process loads the new values.

## 3. Connect Calendar

Log in as a client, open **Demo Bookings**, click **Connect Google Calendar**, and approve the Calendar permission. After connection, confirming a demo creates a Google Meet link automatically.