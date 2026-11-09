import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { CertificateFieldset } from '../components/CertificateFieldset';
import { PdfPlacement } from '../components/PdfPlacement';
import { StampImageField } from '../components/StampImageField';
import {
  ApiError,
  documentsApi,
  printBlob,
  saveBlob,
  type DocumentDetailPayload,
  type SignResponseMeta,
} from '../lib/api';
import { useAuth } from '../lib/auth';
import { formatDateTime, initials, shortHash, slugify, statusLabel } from '../lib/format';
import type {
  CertificateData,
  ConsentResource,
  DeletionRequestResource,
  DocumentResource,
  EventResource,
  Placement,
  SignatureResource,
  SignerResource,
  UserResource,
  VerificationResult,
} from '../lib/types';

/* ------------------------------------------------------------------------- */
/* Signing panel                                                             */
/* ------------------------------------------------------------------------- */

function SignPanel({
  document,
  onSigned,
  onCancel,
}: {
  document: DocumentResource;
  onSigned: (meta: SignResponseMeta) => void;
  onCancel: () => void;
}) {
  const [pdfBlob, setPdfBlob] = useState<Blob | null>(null);
  const [loadingPdf, setLoadingPdf] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [certificate, setCertificate] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [certificateInfo, setCertificateInfo] = useState<CertificateData | null>(null);
  const [image, setImage] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [placement, setPlacement] = useState<Placement>({ page: 1, x: 200, y: 600, width: 260, height: 82 });
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoadingPdf(true);

    documentsApi
      .file(document.id, 'inline')
      .then((blob) => {
        if (!cancelled) {
          setPdfBlob(blob);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setLoadError('No se pudo cargar el PDF del documento.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingPdf(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [document.id]);

  useEffect(() => {
    if (!image) {
      setImagePreview(null);
      return;
    }

    const url = URL.createObjectURL(image);
    setImagePreview(url);

    return () => URL.revokeObjectURL(url);
  }, [image]);

  const ready =
    pdfBlob !== null &&
    certificate !== null &&
    image !== null &&
    certificateInfo !== null &&
    !certificateInfo.expired &&
    password.length > 0 &&
    !submitting;

  const submit = async () => {
    if (!certificate || !image) {
      setError('Completa el certificado y la imagen de la firma.');
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const form = new FormData();
      form.append('certificate', certificate);
      form.append('password', password);
      form.append('image', image);
      form.append('page', String(placement.page));
      form.append('x', String(placement.x));
      form.append('y', String(placement.y));
      form.append('width', String(placement.width));
      form.append('height', String(placement.height));

      if (reason.trim()) {
        form.append('reason', reason.trim());
      }

      const response = await documentsApi.sign(document.id, form);
      onSigned(response.meta);
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.errors.map((item) => item.detail).join(' ')
          : 'No se pudo firmar el documento.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="card sign-panel">
      <div className="card__head">
        <div>
          <h2>Firmar “{document.attributes.title}”</h2>
          <p>
            Firma {document.attributes.signature_count + 1} de {document.attributes.signers_required}. Elige
            gráficamente el lugar de la estampa.
          </p>
        </div>
        <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={submitting}>
          Cancelar
        </button>
      </div>

      {loadingPdf && (
        <div className="page-loading">
          <span className="spinner" aria-hidden="true" /> Cargando PDF…
        </div>
      )}
      {loadError && <div className="alert alert--danger">{loadError}</div>}

      {pdfBlob && (
        <PdfPlacement
          file={pdfBlob}
          page={placement.page}
          onPageChange={(page) => setPlacement((current) => ({ ...current, page }))}
          placement={placement}
          onPlacementChange={setPlacement}
          previewImage={imagePreview}
        />
      )}

      <div className="two-columns">
        <CertificateFieldset
          certificate={certificate}
          onCertificate={setCertificate}
          password={password}
          onPassword={setPassword}
          info={certificateInfo}
          onInfo={setCertificateInfo}
          disabled={submitting}
        />

        <div className="stack">
          <StampImageField image={image} onImage={setImage} disabled={submitting} />

          <div className="field">
            <label className="field__label" htmlFor="sign-reason">
              Motivo <span className="optional">(opcional)</span>
            </label>
            <input
              id="sign-reason"
              className="input"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              maxLength={190}
              placeholder="Aprobación del documento"
            />
          </div>
        </div>
      </div>

      {error && <div className="alert alert--danger">{error}</div>}

      <button type="button" className="btn btn--primary" onClick={submit} disabled={!ready}>
        {submitting ? 'Firmando…' : 'Firmar documento'}
      </button>
      <p className="hint">
        La firma se agrega de forma incremental: las firmas anteriores siguen siendo válidas. Si eres el
        último firmante, el documento se cierra automáticamente.
      </p>
    </section>
  );
}

/* ------------------------------------------------------------------------- */
/* Page                                                                      */
/* ------------------------------------------------------------------------- */

export default function DocumentDetail() {
  const { uuid } = useParams<{ uuid: string }>();
  const { user } = useAuth();
  const navigate = useNavigate();

  const [payload, setPayload] = useState<DocumentDetailPayload | null>(null);
  const [events, setEvents] = useState<EventResource[]>([]);
  const [verification, setVerification] = useState<VerificationResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [showSign, setShowSign] = useState(false);
  const [busy, setBusy] = useState(false);
  const [deletionReason, setDeletionReason] = useState('');

  const load = useCallback(async () => {
    if (!uuid) {
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const [documentResponse, eventsResponse] = await Promise.all([
        documentsApi.get(uuid),
        documentsApi.events(uuid),
      ]);

      setPayload(documentResponse);
      setEvents(eventsResponse.data);

      try {
        const verificationResponse = await documentsApi.verification(uuid);
        setVerification(verificationResponse.data.attributes);
      } catch {
        setVerification(null);
      }
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.message
          : 'No se pudo cargar el documento.',
      );
    } finally {
      setLoading(false);
    }
  }, [uuid]);

  useEffect(() => {
    void load();
  }, [load]);

  const run = async (action: () => Promise<void>) => {
    setBusy(true);
    setActionError(null);
    setMessage(null);

    try {
      await action();
    } catch (requestError) {
      setActionError(
        requestError instanceof ApiError
          ? requestError.errors.map((item) => item.detail).join(' ')
          : 'La acción no pudo completarse.',
      );
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="page">
        <div className="page-loading">
          <span className="spinner" aria-hidden="true" /> Cargando documento…
        </div>
      </div>
    );
  }

  if (error || !payload) {
    return (
      <div className="page">
        <Link to="/app" className="back-link">
          ← Volver al panel
        </Link>
        <div className="alert alert--danger">{error ?? 'Documento no disponible.'}</div>
      </div>
    );
  }

  const document = payload.data;
  const attributes = document.attributes;
  const included = payload.included ?? [];

  const signers = included
    .filter((item) => item.type === 'document-signers')
    .map((item) => item as unknown as SignerResource)
    .sort((left, right) => left.attributes.position - right.attributes.position);

  const signatures = included
    .filter((item) => item.type === 'signatures')
    .map((item) => item as unknown as SignatureResource)
    .sort((left, right) => left.attributes.sequence - right.attributes.sequence);

  const requests = included
    .filter((item) => item.type === 'deletion-requests')
    .map((item) => item as unknown as DeletionRequestResource)
    .sort((left, right) => Number(right.id) - Number(left.id));

  const consents = included
    .filter((item) => item.type === 'deletion-consents')
    .map((item) => item as unknown as ConsentResource);

  const owner = included.find((item) => item.type === 'users') as unknown as UserResource | undefined;

  const pendingRequest = requests.find((request) => request.attributes.status === 'pending') ?? null;

  const pendingConsents = pendingRequest
    ? consents.filter(
        (consent) => consent.relationships?.deletion_request?.data?.id === pendingRequest.id,
      )
    : [];

  const myConsent = pendingConsents.find(
    (consent) => consent.attributes.user_email === user?.attributes.email,
  );

  const progress = Math.round(
    (attributes.signature_count / Math.max(1, attributes.signers_required)) * 100,
  );

  const download = () =>
    void run(async () => {
      const blob = await documentsApi.file(document.id, 'attachment');
      saveBlob(blob, `${slugify(attributes.title)}-firmado.pdf`);
    });

  const print = () =>
    void run(async () => {
      const blob = await documentsApi.file(document.id, 'inline');
      printBlob(blob);
    });

  const removeDocument = () => {
    if (!window.confirm('¿Eliminar definitivamente este documento? Esta acción no se puede deshacer.')) {
      return;
    }

    void run(async () => {
      await documentsApi.remove(document.id);
      navigate('/app');
    });
  };

  const requestDeletion = () => {
    if (
      !window.confirm(
        'Se pedirá el consentimiento de todos los firmantes. El documento solo se eliminará si todos aprueban. ¿Continuar?',
      )
    ) {
      return;
    }

    void run(async () => {
      await documentsApi.requestDeletion(document.id, deletionReason.trim() || null);
      setDeletionReason('');
      setMessage('Solicitud de eliminación creada. Se notificó a todos los firmantes para su consentimiento.');
      await load();
    });
  };

  const decideDeletion = (decision: 'approved' | 'rejected') => {
    if (!pendingRequest) {
      return;
    }

    void run(async () => {
      const response = await documentsApi.consent(document.id, pendingRequest.id, decision, null);

      if (response.meta.document_deleted) {
        navigate('/app');
        return;
      }

      setMessage(
        decision === 'approved'
          ? 'Tu aprobación quedó registrada.'
          : 'Rechazaste la eliminación. El documento se conserva.',
      );
      await load();
    });
  };

  const handleSigned = (meta: SignResponseMeta) => {
    setShowSign(false);
    setMessage(
      meta.sealed
        ? 'Firma registrada. Firmó la última persona: el documento quedó cerrado y ya no admite más firmas.'
        : `Firma registrada. Faltan ${meta.remaining_signatures} firma(s) para cerrar el documento.`,
    );
    void load();
  };

  return (
    <div className="page">
      <Link to="/app" className="back-link">
        ← Volver al panel
      </Link>

      <header className="page-header">
        <div>
          <div className="badge-row">
            <span className={`badge ${attributes.sealed ? 'badge--sealed' : 'badge--open'}`}>
              {statusLabel(attributes.status)}
            </span>
            <span className="badge badge--muted">
              {attributes.my_role === 'owner'
                ? 'Creador'
                : attributes.my_role === 'owner_and_signer'
                  ? 'Creador y firmante'
                  : attributes.my_role === 'signer'
                    ? 'Firmante'
                    : 'Observador'}
            </span>
          </div>
          <h1>{attributes.title}</h1>
          <p>
            Creado por {owner?.attributes.name ?? '—'} · {formatDateTime(attributes.created_at)} ·{' '}
            {attributes.page_count} página(s)
          </p>
        </div>

        <div className="button-row">
          {attributes.permissions.can_download && (
            <>
              <button type="button" className="btn btn--secondary" onClick={download} disabled={busy}>
                Descargar
              </button>
              <button type="button" className="btn btn--ghost" onClick={print} disabled={busy}>
                Imprimir
              </button>
            </>
          )}
          {attributes.permissions.can_sign && !showSign && (
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => setShowSign(true)}
              disabled={busy}
            >
              Firmar documento
            </button>
          )}
        </div>
      </header>

      {message && <div className="alert alert--success">{message}</div>}
      {actionError && <div className="alert alert--danger">{actionError}</div>}

      {attributes.sealed && (
        <div className="alert alert--info">
          El documento está cerrado desde el {formatDateTime(attributes.sealed_at)}. Todos los firmantes
          pueden descargarlo e imprimirlo, pero ya no admite nuevas firmas.
        </div>
      )}

      {showSign && attributes.permissions.can_sign && (
        <SignPanel document={document} onSigned={handleSigned} onCancel={() => setShowSign(false)} />
      )}

      <section className="card">
        <div className="card__head">
          <div>
            <h2>Firmas</h2>
            <p>
              {attributes.signature_count} de {attributes.signers_required} firmas registradas.
            </p>
          </div>
          <span className={`badge ${progress === 100 ? 'badge--signed' : 'badge--pending'}`}>
            {progress}%
          </span>
        </div>

        <div className="progress progress--lg" aria-hidden="true">
          <span style={{ width: `${progress}%` }} />
        </div>

        <div className="signer-list">
          {signers.map((signer) => (
            <div className="signer-row" key={signer.id}>
              <div className="avatar">
                {initials(signer.attributes.user_name ?? signer.attributes.email)}
              </div>
              <div className="signer-row__identity">
                <strong>{signer.attributes.user_name ?? signer.attributes.email}</strong>
                <small>{signer.attributes.email}</small>
              </div>
              <span
                className={`badge ${
                  signer.attributes.status === 'signed' ? 'badge--signed' : 'badge--pending'
                }`}
              >
                {statusLabel(signer.attributes.status)}
              </span>
            </div>
          ))}
        </div>

        {attributes.remaining_signatures > 0 && !attributes.sealed && (
          <p className="hint">
            Faltan {attributes.remaining_signatures} firma(s). El documento se cierra automáticamente al
            firmar la última persona.
          </p>
        )}
      </section>

      {signatures.length > 0 && (
        <section className="card">
          <div className="card__head">
            <div>
              <h2>Firmas registradas</h2>
              <p>Datos criptográficos de cada firma aplicada al PDF.</p>
            </div>
          </div>

          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Firmante</th>
                  <th>Certificado</th>
                  <th>Página</th>
                  <th>Fecha</th>
                  <th>SHA-256 del PDF firmado</th>
                </tr>
              </thead>
              <tbody>
                {signatures.map((signature) => (
                  <tr key={signature.id}>
                    <td>{signature.attributes.sequence}</td>
                    <td>
                      <strong>{signature.attributes.signer_name}</strong>
                      {signature.attributes.signer_email && (
                        <small>{signature.attributes.signer_email}</small>
                      )}
                      {signature.attributes.reason && <small>{signature.attributes.reason}</small>}
                    </td>
                    <td>
                      <span className="mono">{signature.attributes.certificate.serial}</span>
                      <small>{signature.attributes.certificate.issuer}</small>
                      <small className="mono">
                        {shortHash(signature.attributes.certificate.fingerprint_sha256, 20)}
                      </small>
                    </td>
                    <td>{signature.attributes.placement.page}</td>
                    <td>{formatDateTime(signature.attributes.signed_at)}</td>
                    <td className="mono">{shortHash(signature.attributes.signed_sha256, 24)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="card">
        <div className="card__head">
          <div>
            <h2>Verificación del documento</h2>
            <p>Comprobación de integridad y validez criptográfica de cada firma.</p>
          </div>
          {verification && (
            <span
              className={`badge ${verification.integrity_ok ? 'badge--signed' : 'badge--danger'}`}
            >
              {verification.integrity_ok ? 'Integridad correcta' : 'Revisar firmas'}
            </span>
          )}
        </div>

        {verification ? (
          <>
            {verification.signatures.map((signature) => (
              <div className="verification-row" key={signature.field_name}>
                <div className="verification-row__head">
                  <strong>{signature.field_name}</strong>
                  <span className="muted">{signature.signer ?? 'Firmante'}</span>
                </div>
                <div className="chips">
                  <span className={`chip ${signature.intact ? 'chip--ok' : 'chip--bad'}`}>
                    {signature.intact ? 'Bytes intactos' : 'Bytes alterados'}
                  </span>
                  <span
                    className={`chip ${
                      signature.cryptographically_valid ? 'chip--ok' : 'chip--bad'
                    }`}
                  >
                    {signature.cryptographically_valid ? 'Firma válida' : 'Firma inválida'}
                  </span>
                  <span className={`chip ${signature.trusted ? 'chip--ok' : ''}`}>
                    {signature.trusted ? 'Cadena de confianza' : 'Sin cadena de confianza'}
                  </span>
                  {signature.timestamp_time && (
                    <span className="chip">Sellada: {formatDateTime(signature.timestamp_time)}</span>
                  )}
                </div>
                {signature.errors.length > 0 && (
                  <ul className="errors">
                    {signature.errors.map((item) => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                )}
              </div>
            ))}

            <p className="hint">
              {verification.trust_store_configured
                ? 'La cadena de confianza se evalúa contra el almacén de certificados configurado en el servidor.'
                : 'No hay almacén de confianza configurado: se comprueba la integridad de los bytes y la validez criptográfica de la firma, no la autoridad emisora del certificado.'}
            </p>
          </>
        ) : (
          <p className="muted">No se pudo ejecutar la verificación del documento.</p>
        )}
      </section>

      <section className="card">
        <div className="card__head">
          <div>
            <h2>Eliminación</h2>
            <p>
              Un documento firmado solo puede eliminarse cuando todos los firmantes lo aprueban. Un rechazo
              cancela la solicitud.
            </p>
          </div>
        </div>

        {pendingRequest ? (
          <div className="stack">
            <div className="badge-row">
              <span className="badge badge--pending">Solicitud en curso</span>
              <span className="badge badge--muted">
                {pendingRequest.attributes.approved_consents} de{' '}
                {pendingRequest.attributes.required_consents} aprobaciones
              </span>
            </div>

            {pendingRequest.attributes.reason && (
              <p className="muted">Motivo: {pendingRequest.attributes.reason}</p>
            )}

            <ul className="consent-list">
              {pendingConsents.map((consent) => (
                <li key={consent.id}>
                  <span>{consent.attributes.user_name ?? consent.attributes.user_email ?? 'Firmante'}</span>
                  <span
                    className={`badge ${
                      consent.attributes.decision === 'approved' ? 'badge--signed' : 'badge--danger'
                    }`}
                  >
                    {statusLabel(consent.attributes.decision)}
                  </span>
                </li>
              ))}
            </ul>

            {attributes.permissions.can_consent_deletion && !myConsent && (
              <div className="button-row">
                <button
                  type="button"
                  className="btn btn--danger"
                  onClick={() => decideDeletion('approved')}
                  disabled={busy}
                >
                  Aprobar eliminación
                </button>
                <button
                  type="button"
                  className="btn btn--ghost"
                  onClick={() => decideDeletion('rejected')}
                  disabled={busy}
                >
                  Rechazar eliminación
                </button>
              </div>
            )}

            {myConsent && (
              <p className="hint">
                Ya registraste tu decisión: {statusLabel(myConsent.attributes.decision)}.
              </p>
            )}

            {pendingRequest.attributes.pending_consents.length > 0 && (
              <p className="hint">
                Faltan {pendingRequest.attributes.pending_consents.length} firmante(s) por responder.
              </p>
            )}
          </div>
        ) : attributes.permissions.can_request_deletion ? (
          <div className="stack">
            <div className="field">
              <label className="field__label" htmlFor="deletion-reason">
                Motivo de la eliminación <span className="optional">(opcional)</span>
              </label>
              <textarea
                id="deletion-reason"
                className="input"
                rows={2}
                value={deletionReason}
                onChange={(event) => setDeletionReason(event.target.value)}
                maxLength={500}
                placeholder="Explica por qué quieres eliminar el documento"
              />
            </div>
            <button type="button" className="btn btn--danger" onClick={requestDeletion} disabled={busy}>
              Solicitar eliminación con consentimiento
            </button>
          </div>
        ) : attributes.permissions.can_delete ? (
          <div className="stack">
            <p className="muted">Este documento todavía no tiene firmas, por lo que puedes eliminarlo.</p>
            <button type="button" className="btn btn--danger" onClick={removeDocument} disabled={busy}>
              Eliminar documento
            </button>
          </div>
        ) : (
          <p className="muted">No tienes acciones de eliminación disponibles para este documento.</p>
        )}

        {requests.some((request) => request.attributes.status !== 'pending') && (
          <details className="history">
            <summary>Solicitudes anteriores</summary>
            <ul>
              {requests
                .filter((request) => request.attributes.status !== 'pending')
                .map((request) => (
                  <li key={request.id}>
                    {formatDateTime(request.attributes.created_at)} ·{' '}
                    {statusLabel(request.attributes.status)}
                    {request.attributes.reason ? ` · ${request.attributes.reason}` : ''}
                  </li>
                ))}
            </ul>
          </details>
        )}
      </section>

      <section className="card">
        <div className="card__head">
          <div>
            <h2>Historial</h2>
            <p>Registro de actividad del documento.</p>
          </div>
        </div>

        <ol className="timeline">
          {events.map((event) => (
            <li key={event.id}>
              <span className="timeline__dot" aria-hidden="true" />
              <div>
                <strong>{event.attributes.description}</strong>
                <small>
                  {event.attributes.actor_name ?? 'Sistema'} ·{' '}
                  {formatDateTime(event.attributes.created_at)}
                </small>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section className="card">
        <div className="card__head">
          <div>
            <h2>Integridad del archivo</h2>
            <p>Huellas SHA-256 del original y de la versión firmada actual.</p>
          </div>
        </div>
        <dl className="definition-grid">
          <div>
            <dt>SHA-256 original</dt>
            <dd className="mono">{attributes.original_sha256}</dd>
          </div>
          <div>
            <dt>SHA-256 actual</dt>
            <dd className="mono">{attributes.current_sha256}</dd>
          </div>
          <div>
            <dt>Páginas</dt>
            <dd>{attributes.page_count}</dd>
          </div>
          <div>
            <dt>Última actualización</dt>
            <dd>{formatDateTime(attributes.updated_at)}</dd>
          </div>
        </dl>
      </section>
    </div>
  );
}
