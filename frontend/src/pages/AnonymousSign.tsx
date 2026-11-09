import { useEffect, useRef, useState } from 'react';
import { CertificateFieldset } from '../components/CertificateFieldset';
import { FileDrop } from '../components/FileDrop';
import { PdfPlacement } from '../components/PdfPlacement';
import { StampImageField } from '../components/StampImageField';
import { ApiError, anonymousApi, extractFilename } from '../lib/api';
import { formatDateTime, shortHash } from '../lib/format';
import type { CertificateData, Placement } from '../lib/types';

interface SignResult {
  url: string;
  filename: string;
  sha256: string;
  certificateName: string;
  signedAt: string | null;
}

const initialPlacement: Placement = { page: 1, x: 307, y: 702, width: 240, height: 80 };

export default function AnonymousSign() {
  const [pdfFile, setPdfFile] = useState<File | null>(null);
  const [certificate, setCertificate] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [certificateInfo, setCertificateInfo] = useState<CertificateData | null>(null);
  const [image, setImage] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [placement, setPlacement] = useState<Placement>(initialPlacement);
  const [reason, setReason] = useState('');
  const [location, setLocation] = useState('');
  const [signerName, setSignerName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<SignResult | null>(null);
  const resultUrlRef = useRef<string | null>(null);

  useEffect(() => {
    if (!image) {
      setImagePreview(null);
      return;
    }

    const url = URL.createObjectURL(image);
    setImagePreview(url);

    return () => URL.revokeObjectURL(url);
  }, [image]);

  useEffect(() => {
    return () => {
      if (resultUrlRef.current) {
        URL.revokeObjectURL(resultUrlRef.current);
      }
    };
  }, []);

  const ready =
    pdfFile !== null &&
    certificate !== null &&
    image !== null &&
    certificateInfo !== null &&
    !certificateInfo.expired &&
    password.length > 0 &&
    !submitting;

  const reset = () => {
    if (resultUrlRef.current) {
      URL.revokeObjectURL(resultUrlRef.current);
      resultUrlRef.current = null;
    }

    setResult(null);
    setPdfFile(null);
    setCertificate(null);
    setPassword('');
    setCertificateInfo(null);
    setImage(null);
    setReason('');
    setLocation('');
    setSignerName('');
    setPlacement(initialPlacement);
    setError(null);
  };

  const submit = async () => {
    if (!pdfFile || !certificate || !image) {
      setError('Completa el PDF, el certificado y la imagen de la firma.');
      return;
    }

    setSubmitting(true);
    setError(null);

    try {
      const form = new FormData();
      form.append('pdf', pdfFile);
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

      if (location.trim()) {
        form.append('location', location.trim());
      }

      if (signerName.trim()) {
        form.append('signer_name', signerName.trim());
      }

      const response = await anonymousApi.sign(form);
      const blob = await response.blob();

      if (resultUrlRef.current) {
        URL.revokeObjectURL(resultUrlRef.current);
      }

      const url = URL.createObjectURL(blob);
      resultUrlRef.current = url;

      setResult({
        url,
        filename: extractFilename(response),
        sha256: response.headers.get('X-Signature-Sha256') ?? '',
        certificateName: response.headers.get('X-Certificate-Cn') ?? certificateInfo?.common_name ?? '',
        signedAt: response.headers.get('X-Signed-At'),
      });
    } catch (requestError) {
      setError(
        requestError instanceof ApiError
          ? requestError.message
          : 'No se pudo firmar el documento. Intenta nuevamente.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="page">
      <section className="hero">
        <div className="hero__text">
          <span className="eyebrow">Sin registro · Uso inmediato</span>
          <h1>Firma un PDF con tu certificado .p12</h1>
          <p>
            Sube el documento y tu certificado, elige gráficamente el lugar de la firma y descarga el PDF
            firmado con la estampa de tu imagen y los datos criptográficos del certificado.
          </p>
          <ul className="hero__points">
            <li>La firma es un CMS/PAdES real aplicado al PDF completo.</li>
            <li>Nada se almacena: todo se procesa en memoria y se devuelve al instante.</li>
            <li>Tu contraseña solo se usa para abrir el certificado y firmar.</li>
          </ul>
        </div>

        <ol className="steps-card">
          <li>
            <span>1</span> Sube el PDF y colócalo en el visor.
          </li>
          <li>
            <span>2</span> Valida el .p12 con su contraseña.
          </li>
          <li>
            <span>3</span> Arrastra el recuadro de firma.
          </li>
          <li>
            <span>4</span> Descarga el documento firmado.
          </li>
        </ol>
      </section>

      {result ? (
        <section className="card result-card">
          <div className="result-card__icon" aria-hidden="true">
            ✓
          </div>
          <div className="result-card__body">
            <h2>Documento firmado correctamente</h2>
            <p>
              La firma quedó aplicada y verificada criptográficamente. Descarga el PDF firmado y consérvalo;
              el sistema no guarda ninguna copia.
            </p>
            <dl className="definition-grid">
              <div>
                <dt>Firmante</dt>
                <dd>{result.certificateName || '—'}</dd>
              </div>
              <div>
                <dt>Fecha de firma</dt>
                <dd>{formatDateTime(result.signedAt)}</dd>
              </div>
              <div>
                <dt>SHA-256 del PDF firmado</dt>
                <dd className="mono">{shortHash(result.sha256, 32)}</dd>
              </div>
              <div>
                <dt>Patrón de verificación</dt>
                <dd>CMS/PAdES con firma incremental</dd>
              </div>
            </dl>
            <div className="button-row">
              <a className="btn btn--primary" href={result.url} download={result.filename}>
                Descargar PDF firmado
              </a>
              <button type="button" className="btn btn--ghost" onClick={reset}>
                Firmar otro documento
              </button>
            </div>
          </div>
        </section>
      ) : (
        <section className="signature-layout">
          <div className="card signature-layout__preview">
            <div className="card__head">
              <div>
                <h2>1. Documento y ubicación de la firma</h2>
                <p>Sube el PDF y arrastra el recuadro hasta el lugar donde quieres estampar la firma.</p>
              </div>
              {pdfFile && (
                <span className="badge badge--muted">
                  {pdfFile.name.length > 32 ? `${pdfFile.name.slice(0, 32)}…` : pdfFile.name}
                </span>
              )}
            </div>

            <FileDrop
              label="Documento PDF"
              hint="PDF sin contraseña, hasta 20 MB"
              accept="application/pdf,.pdf"
              file={pdfFile}
              onFile={(file) => {
                setPdfFile(file);
                setPlacement(initialPlacement);
                setError(null);
              }}
              required
            />

            <PdfPlacement
              file={pdfFile}
              page={placement.page}
              onPageChange={(page) => setPlacement((current) => ({ ...current, page }))}
              placement={placement}
              onPlacementChange={setPlacement}
              previewImage={imagePreview}
            />
          </div>

          <aside className="card signature-layout__form">
            <div className="card__head">
              <div>
                <h2>2. Certificado y estampa</h2>
                <p>La imagen y los datos del certificado se dibujan dentro del recuadro elegido.</p>
              </div>
            </div>

            <CertificateFieldset
              certificate={certificate}
              onCertificate={setCertificate}
              password={password}
              onPassword={setPassword}
              info={certificateInfo}
              onInfo={setCertificateInfo}
              disabled={submitting}
            />

            <StampImageField image={image} onImage={setImage} disabled={submitting} />

            <div className="two-columns">
              <div className="field">
                <label className="field__label" htmlFor="reason">
                  Motivo <span className="optional">(opcional)</span>
                </label>
                <input
                  id="reason"
                  className="input"
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  maxLength={190}
                  placeholder="Aprobación del documento"
                />
              </div>
              <div className="field">
                <label className="field__label" htmlFor="location">
                  Lugar <span className="optional">(opcional)</span>
                </label>
                <input
                  id="location"
                  className="input"
                  value={location}
                  onChange={(event) => setLocation(event.target.value)}
                  maxLength={190}
                  placeholder="Ciudad"
                />
              </div>
            </div>

            <div className="field">
              <label className="field__label" htmlFor="signer-name">
                Nombre del firmante <span className="optional">(opcional)</span>
              </label>
              <input
                id="signer-name"
                className="input"
                value={signerName}
                onChange={(event) => setSignerName(event.target.value)}
                maxLength={190}
                placeholder="Se toma del certificado si lo dejas vacío"
              />
            </div>

            {error && <div className="alert alert--danger">{error}</div>}

            {!certificateInfo && password.length > 0 && (
              <div className="alert alert--info">
                Valida el certificado para habilitar la firma y confirmar que la contraseña es correcta.
              </div>
            )}

            <button type="button" className="btn btn--primary btn--block" onClick={submit} disabled={!ready}>
              {submitting ? 'Firmando documento…' : 'Firmar y descargar PDF'}
            </button>
            <p className="hint">
              Al firmar se aplica una firma digital CMS/PAdES sobre el documento. Esta aplicación no guarda
              copia del PDF, del certificado ni de la contraseña.
            </p>
          </aside>
        </section>
      )}
    </div>
  );
}
