"""Sphinx configuration for the KING documentation site."""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

project = "KING"
author = "Intelligent Intern"
copyright = "2026, Intelligent Intern"
release = "1.0.8-beta"

extensions = [
    "sphinx.ext.extlinks",
]

templates_path = ["_templates"]
exclude_patterns = ["_build", "Thumbs.db", ".DS_Store"]

html_theme = "sphinx_rtd_theme"
html_title = "KING documentation"
html_show_sourcelink = True
html_context = {
    "display_github": True,
    "github_user": "sashakolpakov",
    "github_repo": "king",
    "github_version": "main",
    "conf_py_path": "/docs/",
}

extlinks = {
    "repo": ("https://github.com/sashakolpakov/king/blob/main/%s", "%s"),
    "src": ("https://github.com/sashakolpakov/king/tree/main/%s", "%s"),
}
