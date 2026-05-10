import { descriptorAllowsAction } from './crudDescriptors.js';

function translateKey(translate, key, params = {}) {
  return typeof translate === 'function' ? translate(key, params) : key;
}

export function governanceReadonlyReason({ descriptor = {}, routeMeta = {}, translate } = {}) {
  const routeReasonKey = String(routeMeta?.readonly_reason_key || '').trim();
  const descriptorReasonKey = String(descriptor?.readonly_reason_key || '').trim();
  const reasonKey = routeReasonKey || descriptorReasonKey;
  return reasonKey === '' ? '' : translateKey(translate, reasonKey);
}

export function governanceEmptyStateBody({
  descriptor = {},
  routeMeta = {},
  singularLabel = '',
  translate,
} = {}) {
  const entity = String(singularLabel || '').toLocaleLowerCase();
  const bodyKey = String(descriptor?.empty_state_body_key || '').trim();
  if (bodyKey !== '') return translateKey(translate, bodyKey, { entity });
  if (descriptor?.readonly === true) {
    return governanceReadonlyReason({ descriptor, routeMeta, translate })
      || translateKey(translate, 'governance.empty_state.readonly_body', { entity });
  }
  if (!descriptorAllowsAction(descriptor, 'create')) {
    return translateKey(translate, 'governance.empty_state.no_create_body', { entity });
  }
  return translateKey(translate, 'governance.empty_state.body', { entity });
}
