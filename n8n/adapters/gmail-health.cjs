'use strict';
// Return a fixed diagnostic code, never provider messages, tokens or request data.
function gmailAccountResult(profile, read) {
  if (typeof profile?.emailAddress === 'string' && Number.isFinite(read?.resultSizeEstimate)) {
    return {ok:true,emailAddress:profile.emailAddress,read_access:true};
  }
  const failure = JSON.stringify([profile?.error, read?.error]).toLowerCase();
  const code = /service_disabled|accessnotconfigured|api has not been used|api.*disabled/.test(failure)
    ? 'GMAIL_API_DISABLED'
    : /invalid_grant|invalid_token|expired|revoked|unauthenticated/.test(failure)
      ? 'GMAIL_REAUTHORIZE_REQUIRED'
      : /insufficient.*(?:scope|permission)|access_token_scope_insufficient/.test(failure)
        ? 'GMAIL_READ_SCOPE_REQUIRED'
        : /quota|rate.limit|resource_exhausted/.test(failure)
          ? 'GMAIL_RATE_LIMITED'
          : 'GMAIL_AUTHORIZATION_OR_READ_SCOPE_REQUIRED';
  return {ok:false,error_code:code};
}
module.exports = {gmailAccountResult};
