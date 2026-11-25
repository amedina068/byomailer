interface PermissionItem {
    action: string;
    description: string;
}

interface PermissionCategory {
    category: string;
    items: PermissionItem[];
}

const sesAndSnsActions = [
    'ses:GetAccount',
    'ses:SendEmail',
    'ses:SendRawEmail',
    'ses:SendBulkEmail',
    'ses:SendBulkTemplatedEmail',
    'ses:CreateEmailIdentity',
    'ses:GetEmailIdentity',
    'ses:PutEmailIdentityMailFromAttributes',
    'ses:PutEmailIdentityConfigurationSetAttributes',
    'ses:CreateTemplate',
    'ses:DeleteTemplate',
    'ses:ListTemplates',
    'ses:ListConfigurationSets',
    'ses:GetConfigurationSet',
    'ses:GetConfigurationSetEventDestinations',
    'ses:CreateConfigurationSet',
    'ses:CreateConfigurationSetEventDestination',
    'ses:UpdateConfigurationSetEventDestination',
    'ses:PutConfigurationSetTrackingOptions',
    'ses:DeleteConfigurationSet',
    'ses:CreateTenant',
    'ses:CreateTenantResourceAssociation',
    'ses:GetTenant',
    'ses:UpdateReputationEntityPolicy',
    'ses:DeleteTenantResourceAssociation',
    'sns:CreateTopic',
    'sns:DeleteTopic',
    'sns:Subscribe',
    'sns:Unsubscribe',
];

const identityActions = ['sts:GetCallerIdentity', 'iam:SimulatePrincipalPolicy'];

const policyDocument = {
    Version: '2012-10-17',
    Statement: [
        {
            Effect: 'Allow',
            Action: sesAndSnsActions,
            Resource: '*',
        },
        {
            Effect: 'Allow',
            Action: identityActions,
            Resource: '*',
        },
    ],
};

export const iamPolicyJson: string = JSON.stringify(policyDocument, null, 4);

export const iamPermissions: PermissionCategory[] = [
    {
        category: 'Email Sending',
        items: [
            { action: 'ses:SendEmail', description: 'Send formatted emails through SES.' },
            { action: 'ses:SendRawEmail', description: 'Send raw MIME emails, used for attachments and custom headers.' },
            { action: 'ses:SendBulkEmail', description: 'Send bulk emails efficiently in batches.' },
            { action: 'ses:SendBulkTemplatedEmail', description: 'Send bulk emails using SES templates.' },
        ],
    },
    {
        category: 'Account & Identity Management',
        items: [
            { action: 'ses:GetAccount', description: 'Retrieve your SES account details including sending limits and quotas.' },
            { action: 'ses:CreateEmailIdentity', description: 'Register a new email address or domain as a verified sender.' },
            { action: 'ses:GetEmailIdentity', description: 'Check the verification status of an email identity.' },
            {
                action: 'ses:PutEmailIdentityMailFromAttributes',
                description: 'Configure the custom MAIL FROM domain for an identity.',
            },
            {
                action: 'ses:PutEmailIdentityConfigurationSetAttributes',
                description: 'Set the default configuration set on an email identity.',
            },
        ],
    },
    {
        category: 'Template Management',
        items: [
            { action: 'ses:CreateTemplate', description: 'Create reusable email templates in SES.' },
            { action: 'ses:DeleteTemplate', description: 'Remove email templates from SES.' },
            { action: 'ses:ListTemplates', description: 'List all available email templates in your account.' },
        ],
    },
    {
        category: 'Configuration Sets',
        items: [
            {
                action: 'ses:ListConfigurationSets',
                description: "List all configuration sets. Used to check if BYOMailer's set exists.",
            },
            { action: 'ses:GetConfigurationSet', description: 'Retrieve details of a specific configuration set.' },
            {
                action: 'ses:GetConfigurationSetEventDestinations',
                description: 'Read event destinations to detect open and click tracking status.',
            },
            {
                action: 'ses:CreateConfigurationSet',
                description: 'Create a configuration set for tracking email events (opens, clicks, bounces).',
            },
            {
                action: 'ses:CreateConfigurationSetEventDestination',
                description: 'Configure where email events are sent (SNS topic).',
            },
            { action: 'ses:UpdateConfigurationSetEventDestination', description: 'Update event destination settings.' },
            {
                action: 'ses:PutConfigurationSetTrackingOptions',
                description: 'Configure open and click tracking for a configuration set.',
            },
            { action: 'ses:DeleteConfigurationSet', description: 'Remove a configuration set from your account.' },
        ],
    },
    {
        category: 'Tenant Isolation',
        items: [
            {
                action: 'ses:CreateTenant',
                description: 'Create an isolated tenant within your SES account for BYOMailer sends.',
            },
            {
                action: 'ses:CreateTenantResourceAssociation',
                description: 'Associate email identities and configuration sets with the BYOMailer tenant.',
            },
            {
                action: 'ses:GetTenant',
                description: 'Retrieve tenant details when the tenant already exists.',
            },
            {
                action: 'ses:UpdateReputationEntityPolicy',
                description: 'Set the reputation policy on the BYOMailer tenant.',
            },
            {
                action: 'ses:DeleteTenantResourceAssociation',
                description: 'Remove resource associations from the tenant when cleaning up templates.',
            },
        ],
    },
    {
        category: 'SNS (Notifications)',
        items: [
            {
                action: 'sns:CreateTopic',
                description: 'Create an SNS topic to receive SES event notifications (bounces, complaints, deliveries).',
            },
            { action: 'sns:DeleteTopic', description: 'Remove an SNS topic.' },
            {
                action: 'sns:Subscribe',
                description: "Subscribe BYOMailer's endpoint to receive notifications from the SNS topic.",
            },
            { action: 'sns:Unsubscribe', description: 'Remove a subscription from an SNS topic.' },
        ],
    },
    {
        category: 'Identity & Permission Verification',
        items: [
            {
                action: 'sts:GetCallerIdentity',
                description: 'Verify that the provided AWS credentials are valid before saving them.',
            },
            {
                action: 'iam:SimulatePrincipalPolicy',
                description: 'Check which SES permissions your IAM user has without making real API calls.',
            },
        ],
    },
];
