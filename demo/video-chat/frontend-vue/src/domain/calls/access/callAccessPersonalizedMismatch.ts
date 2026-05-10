export function createPersonalizedMismatchState() {
  return {
    visible: false,
    step: 'host',
    hostName: '',
    firstName: '',
    lastName: '',
  };
}

export function resetPersonalizedMismatchState(state: Record<string, any>) {
  state.visible = false;
  state.step = 'host';
  state.hostName = '';
  state.firstName = '';
  state.lastName = '';
}

function errorDetailsField(errorDetails: Record<string, any>, field: string): string {
  const fields = errorDetails && typeof errorDetails === 'object' && errorDetails.fields && typeof errorDetails.fields === 'object'
    ? errorDetails.fields
    : {};
  return String(fields[field] || '').trim();
}

export function applyPersonalizedMismatchError(
  mismatchState: Record<string, any>,
  result: Record<string, any>,
  translate: (key: string) => string,
): string {
  const details = result && typeof result === 'object' && result.errorDetails && typeof result.errorDetails === 'object'
    ? result.errorDetails
    : {};
  if (String(details.mismatch || '') !== 'strong_personalized_link') return '';

  const hostField = errorDetailsField(details, 'host_name');
  if (!['not_verified', 'verified'].includes(hostField)) return '';

  mismatchState.visible = true;
  mismatchState.step = hostField === 'verified' ? 'update' : 'host';
  return translate('public.join.personalized_mismatch_verify_host');
}
