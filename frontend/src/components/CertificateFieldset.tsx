import { useEffect, useState } from 'react';
import { ApiError, anonymousApi } from '../lib/api';
import { formatDate } from '../lib/format';
import type { CertificateData } from '../lib/types';
import { FileDrop } from './FileDrop';

interface CertificateFieldsetProps {
  certificate: File | null;
  onCertificate: (file: File | null) => void;
  password: string;
  onPassword: (password: string) => void;
  info: CertificateData | null;
  onInfo: (info: CertificateData | null) => void;
  disabled?: boolean;
}

export function CertificateFieldset({
  certificate,
  onCertificate,
  password,
  onPassword,
  info,
  onInfo,
  disabled = false,
}: CertificateFieldsetProps) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [revealed, setRevealed] = useState(false);

  useEffect(() => {
    onInfo(null);
    setError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [certificate, password]);

  const validate = async () => {
    if (!certificate) {
      setError('Sube tu certificado .p12 o .pfx.');
      return;
    }

    if (!password) {
      setError('Ingresa la contraseña del certificado.');
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const form = new FormData();
      form.append('certificate', certificate);
      form.append('password', password);
      const data = await anonymousApi.inspectCertificate(form);
      onInfo(data);
    } catch (requestError) {
      onInfo(null);
      setError(
        requestError instanceof ApiError
          ? requestError.message
          : 'No se pudo validar el certificado. Intenta nuevamente.',
      );
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="stack">
      <FileDrop
        label="Certificado digital"
        hint="Archivo .p12 o .pfx con tu clave privada"
        accept=".p12,.pfx,application/x-pkcs12,application/pkcs12"
        file={certificate}
        onFile={onCertificate}
        disabled={disabled}
        required
      />

      <div className="field">
        <label className="field__label" htmlFor="certificate-password">
          Contraseña del certificado <span className="required">*</span>
        </label>
        <div className="input-row">
          <input
            id="certificate-password"
            className="input"
            type={revealed ? 'text' : 'password'}
            value={password}
            onChange={(event) => onPassword(event.target.value)}
            placeholder="Contraseña del .p12"
            autoComplete="off"
            disabled={disabled}
          />
          <button
            type="button"
            className="btn btn--ghost btn--sm"
            onClick={() => setRevealed((value) => !value)}
            disabled={disabled}
          >
            {revealed ? 'Ocultar' : 'Ver'}
          </button>
        </div>
      </div>

      <button
        type="button"
        className="btn btn--secondary"
        onClick={validate}
        disabled={disabled || loading || !certificate || !password}
      >
        {loading ? 'Validando…' : 'Validar certificado'}
      </button>

      {error && <div className="alert alert--danger">{error}</div>}

      {info && (
        <div className={`cert-card${info.expired ? ' cert-card--expired' : ''}`}>
          <div className="cert-card__head">
            <span aria-hidden="true">{info.expired ? '⚠' : '✓'}</span>
            <div>
              <strong>{info.common_name ?? info.subject}</strong>
              <small>{info.expired ? 'Certificado vencido' : 'Certificado validado'}</small>
            </div>
          </div>
          <dl className="definition-grid">
            <div>
              <dt>N.º de serie</dt>
              <dd className="mono">{info.serial}</dd>
            </div>
            <div>
              <dt>Emisor</dt>
              <dd>{info.issuer}</dd>
            </div>
            <div>
              <dt>Válido desde</dt>
              <dd>{formatDate(info.valid_from)}</dd>
            </div>
            <div>
              <dt>Válido hasta</dt>
              <dd>{formatDate(info.valid_to)}</dd>
            </div>
          </dl>
        </div>
      )}
    </div>
  );
}
