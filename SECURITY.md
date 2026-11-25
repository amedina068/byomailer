# Security policy

## Supported versions

Only the latest release gets security fixes. If you run an older version, update to the latest release first.

## Report a security problem

Do not open a public issue, a discussion or a pull request for a security problem. A public report shows the problem to attackers before a fix exists.

Report the problem in private through a GitHub security advisory:

1. Open the **Security** tab of this repository.
2. Click **Report a vulnerability**.
3. Fill in the form and submit it.

Only the maintainer can read the report. Put these items in the report:

- The version or the commit that you tested.
- The install method: the Docker image, Docker Compose from source, or a local install.
- The steps that show the problem.
- The effect: what an attacker can read, change or send.
- A fix or a mitigation, if you have one.

Do not put real contacts, real email addresses, real AWS keys or real API tokens in the report.

## What happens next

One person maintains this project, so answers are not immediate. The maintainer reads each report and replies in the advisory. If the report is correct, the maintainer prepares a fix in the private advisory and publishes a release. Then the advisory becomes public. The advisory gives you credit, unless you ask to stay anonymous.

## Scope

These problems are in scope:

- Authentication, authorization and tenant isolation: one account that reads or changes the contacts, the campaigns or the settings of another account.
- Access to the stored AWS credentials of an account, or a send through the SES account of another account.
- The pages that a contact opens from an email: the unsubscribe page, the preference center and the opt-in page. For example, a change to the subscription of another contact.
- Forged SES events: an SNS or SQS message that BYOMailer accepts without a valid signature.
- Server-side request forgery through outgoing webhooks, storage endpoints, image URLs or custom domains.
- Script injection in the email editor, the templates, the content blocks or the dashboard.
- Leaks of secrets, API tokens, MCP tokens or signed URLs.

These problems are not in scope:

- Problems in an instance that you do not own. Test on your own instance only.
- Denial of service from a large volume of requests.
- Problems that need physical access or a compromised server.
- Problems in Amazon SES, SNS or SQS themselves. Report them to AWS.
- Missing security headers without a demonstrated effect.
