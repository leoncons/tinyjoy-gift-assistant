"""Google Drive destination: folder resolution and uploads."""

from __future__ import annotations

import mimetypes
import os
from pathlib import Path

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google.oauth2.service_account import Credentials as ServiceCredentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

SCOPES = ["https://www.googleapis.com/auth/drive"]
FOLDER_MIME = "application/vnd.google-apps.folder"
SHARED = {"supportsAllDrives": True, "includeItemsFromAllDrives": True}


def _escape(name: str) -> str:
    return name.replace("\\", "\\\\").replace("'", "\\'")


class DriveClient:
    def __init__(
        self,
        credentials_file: str = "credentials.json",
        token_file: str = "token.json",
        auth_mode: str = "oauth",
    ) -> None:
        self.service = build(
            "drive", "v3", credentials=self._auth(credentials_file, token_file, auth_mode)
        )
        self._folder_cache: dict[tuple[str, str], str] = {}

    @staticmethod
    def _auth(credentials_file: str, token_file: str, auth_mode: str):
        if auth_mode == "service_account":
            return ServiceCredentials.from_service_account_file(
                credentials_file, scopes=SCOPES
            )

        creds = None
        if os.path.exists(token_file):
            creds = Credentials.from_authorized_user_file(token_file, SCOPES)
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        if not creds or not creds.valid:
            flow = InstalledAppFlow.from_client_secrets_file(credentials_file, SCOPES)
            creds = flow.run_local_server(port=0)
            Path(token_file).write_text(creds.to_json(), encoding="utf-8")
        return creds

    # --- folders -------------------------------------------------------

    def resolve_path(self, path: str) -> str:
        """Return the folder id for a Drive path, creating missing folders.

        Accepts a raw folder id, "My Drive/a/b" or "Shared Drive Name/a/b".
        """
        path = (path or "").strip().strip("/")
        if not path:
            return "root"
        if "/" not in path and self._looks_like_id(path):
            return path

        parts = [p for p in path.split("/") if p]
        parent = "root"
        if parts[0].lower() in {"my drive", "mydrive", "root"}:
            parts = parts[1:]
        else:
            drive_id = self._find_shared_drive(parts[0])
            if drive_id:
                parent = drive_id
                parts = parts[1:]

        for part in parts:
            parent = self.ensure_subfolder(parent, part)
        return parent

    @staticmethod
    def _looks_like_id(value: str) -> bool:
        return len(value) >= 19 and " " not in value

    def _find_shared_drive(self, name: str) -> str | None:
        resp = self.service.drives().list(pageSize=100, fields="drives(id,name)").execute()
        for drive in resp.get("drives", []):
            if drive["name"].lower() == name.lower():
                return drive["id"]
        return None

    def ensure_subfolder(self, parent: str, name: str) -> str:
        """Return a child folder id, creating it when missing."""
        key = (parent, name.lower())
        if key in self._folder_cache:
            return self._folder_cache[key]

        query = (
            f"name = '{_escape(name)}' and mimeType = '{FOLDER_MIME}' "
            f"and '{parent}' in parents and trashed = false"
        )
        resp = (
            self.service.files()
            .list(q=query, fields="files(id,name)", pageSize=1, **SHARED)
            .execute()
        )
        files = resp.get("files", [])
        if files:
            folder_id = files[0]["id"]
        else:
            folder_id = (
                self.service.files()
                .create(
                    body={"name": name, "mimeType": FOLDER_MIME, "parents": [parent]},
                    fields="id",
                    supportsAllDrives=True,
                )
                .execute()["id"]
            )
        self._folder_cache[key] = folder_id
        return folder_id

    # --- files ---------------------------------------------------------

    def find_file(self, parent: str, name: str) -> dict | None:
        query = (
            f"name = '{_escape(name)}' and '{parent}' in parents and trashed = false"
        )
        resp = (
            self.service.files()
            .list(q=query, fields="files(id,name,size)", pageSize=1, **SHARED)
            .execute()
        )
        files = resp.get("files", [])
        return files[0] if files else None

    def upload(self, local_path: str | Path, parent: str, name: str | None = None,
               overwrite: bool = False) -> dict:
        """Upload a local file, skipping or replacing an existing same-name file."""
        local_path = Path(local_path)
        name = name or local_path.name
        existing = self.find_file(parent, name)
        if existing and not overwrite:
            return {**existing, "skipped": True}

        mime = mimetypes.guess_type(name)[0] or "application/octet-stream"
        media = MediaFileUpload(str(local_path), mimetype=mime, resumable=True)
        if existing:
            file = (
                self.service.files()
                .update(fileId=existing["id"], media_body=media, fields="id,name,webViewLink",
                        supportsAllDrives=True)
                .execute()
            )
        else:
            file = (
                self.service.files()
                .create(
                    body={"name": name, "parents": [parent]},
                    media_body=media,
                    fields="id,name,webViewLink",
                    supportsAllDrives=True,
                )
                .execute()
            )
        return {**file, "skipped": False}

    def download_text(self, parent: str, name: str) -> str | None:
        found = self.find_file(parent, name)
        if not found:
            return None
        data = self.service.files().get_media(fileId=found["id"], supportsAllDrives=True).execute()
        return data.decode("utf-8") if isinstance(data, bytes) else str(data)
