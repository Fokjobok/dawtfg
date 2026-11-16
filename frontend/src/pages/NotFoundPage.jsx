import { Link } from 'react-router'
import styles from './HomePage.module.scss'

export default function NotFoundPage() {
  return (
    <section className={styles.intro} aria-labelledby="not-found-title">
      <p className={styles.eyebrow}>Error 404</p>
      <h1 id="not-found-title">Página no encontrada</h1>
      <p>La dirección que has solicitado no existe.</p>
      <Link to="/">Volver al inicio</Link>
    </section>
  )
}
