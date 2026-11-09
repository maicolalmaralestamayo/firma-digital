export interface UserResource {
  type: 'users';
  id: string;
  attributes: {
    name: string;
    email: string;
    created_at: string | null;
  };
}

export interface DocumentPermissions {
  can_sign: boolean;
  can_download: boolean;
  can_delete: boolean;
  can_request_deletion: boolean;
  can_consent_deletion: boolean;
}

export interface DocumentAttributes {
  title: string;
  status: 'open' | 'sealed';
  sealed: boolean;
  signers_required: number;
  signature_count: number;
  remaining_signatures: number;
  page_count: number;
  original_sha256: string;
  current_sha256: string;
  sealed_at: string | null;
  created_at: string;
  updated_at: string;
  my_role: 'owner' | 'owner_and_signer' | 'signer' | 'observer';
  my_signature_status: 'pending' | 'signed' | null;
  permissions: DocumentPermissions;
}

export interface DocumentResource {
  type: 'documents';
  id: string;
  attributes: DocumentAttributes;
  relationships?: {
    owner?: { data: { type: string; id: string } | null };
    signers?: { data: Array<{ type: string; id: string }> };
    signatures?: { data: Array<{ type: string; id: string }> };
  };
  links?: Record<string, string>;
}

export interface SignerResource {
  type: 'document-signers';
  id: string;
  attributes: {
    email: string;
    position: number;
    status: 'pending' | 'signed';
    claimed: boolean;
    invited_at: string | null;
    signed_at: string | null;
    user_name: string | null;
  };
}

export interface CertificateData {
  subject: string;
  common_name: string | null;
  email: string | null;
  serial: string;
  issuer: string;
  fingerprint_sha256: string;
  valid_from: string | null;
  valid_to: string | null;
  expired: boolean;
}

export interface SignatureResource {
  type: 'signatures';
  id: string;
  attributes: {
    field_name: string;
    sequence: number;
    signer_name: string;
    signer_email: string | null;
    certificate: CertificateData;
    signed_at: string;
    placement: {
      page: number;
      x: number;
      y: number;
      width: number;
      height: number;
      coordinate_system: string;
    };
    previous_sha256: string | null;
    signed_sha256: string;
    reason: string | null;
    location: string | null;
    created_at: string;
  };
}

export interface DeletionRequestResource {
  type: 'deletion-requests';
  id: string;
  attributes: {
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    reason: string | null;
    required_consents: number;
    approved_consents: number;
    rejected_consents: number;
    pending_consents: number[];
    created_at: string;
    resolved_at: string | null;
  };
}

export interface ConsentResource {
  type: 'deletion-consents';
  id: string;
  relationships?: {
    deletion_request?: { data: { type: string; id: string } | null };
    user?: { data: { type: string; id: string } | null };
  };
  attributes: {
    decision: 'approved' | 'rejected';
    comment: string | null;
    decided_at: string;
    user_name: string | null;
    user_email: string | null;
  };
}

export interface EventResource {
  type: 'document-events';
  id: string;
  attributes: {
    type: string;
    description: string;
    meta: Record<string, unknown>;
    actor_name: string | null;
    created_at: string;
  };
}

export interface VerificationSignature {
  field_name: string;
  intact: boolean;
  cryptographically_valid: boolean;
  trusted: boolean;
  covers_whole_file: boolean;
  signer: string | null;
  claimed_signing_time: string | null;
  timestamp_time: string | null;
  errors: string[];
  warnings: string[];
}

export interface VerificationResult {
  document_status: string;
  sha256: string;
  trust_store_configured: boolean;
  all_valid: boolean;
  all_trusted: boolean;
  integrity_ok: boolean;
  signatures: VerificationSignature[];
}

export interface Pagination {
  page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface Collection<T> {
  data: T[];
  included?: Array<{ type: string; id: string; attributes: Record<string, unknown> }>;
  meta: { pagination: Pagination };
  links: Record<string, string>;
}

export interface JsonApiError {
  status: string;
  code: string;
  title: string;
  detail: string;
  source?: { pointer?: string };
  meta?: Record<string, unknown>;
}

export interface Placement {
  page: number;
  x: number;
  y: number;
  width: number;
  height: number;
}
