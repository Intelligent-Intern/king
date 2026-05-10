export function createWorkspaceProfileSettingsDraftFields() {
  return {
    aboutMe: '',
    linkedinUrl: '',
    xUrl: '',
    youtubeUrl: '',
    profileContactEmail: '',
    profileContactPhone: '',
  };
}

export function resetWorkspaceProfileSettingsDraft(draft, sessionState) {
  draft.aboutMe = sessionState.aboutMe || '';
  draft.linkedinUrl = sessionState.linkedinUrl || '';
  draft.xUrl = sessionState.xUrl || '';
  draft.youtubeUrl = sessionState.youtubeUrl || '';
  draft.profileContactEmail = sessionState.profileContactEmail || '';
  draft.profileContactPhone = sessionState.profileContactPhone || '';
}

export function workspaceProfileSettingsPayload(draft) {
  return {
    about_me: draft.aboutMe,
    linkedin_url: draft.linkedinUrl,
    x_url: draft.xUrl,
    youtube_url: draft.youtubeUrl,
    profile_contact_email: draft.profileContactEmail,
    profile_contact_phone: draft.profileContactPhone,
  };
}
