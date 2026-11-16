import { useState } from 'react'
import { Link } from 'react-router'
import Button from '../components/Button.jsx'
import { getHealth } from '../services/api.js'
import styles from './HomePage.module.scss'

export default function HomePage() {
  const [status, setStatus] = useState('idle')

  async function checkHealth() {
    setStatus('loading')

    try {
      await getHealth()
      setStatus('success')
    } catch {
      setStatus('error')
    }
  }

  const messages = {
    idle: 'Puedes comprobar la conexión con la API.',
    loading: 'Comprobando la conexión…',
    success: 'La API está disponible.',
    error:
      'No se pudo contactar con la API. Comprueba que el backend esté iniciado.',
  }

  return (
    <section className={styles.grid} aria-labelledby="home-title">
      <div className={styles.intro}>
        <p className={styles.eyebrow}>Aprendizaje continuo</p>
        <h1 id="home-title">Formación y coaching</h1>
        <p>Una plataforma para aprender y mejorar, en preparación.</p>
        <Link to="/account">
          Acceder a mi cuenta <span aria-hidden="true">→</span>
        </Link>
      </div>
      <div className={styles.connection}>
        <h2>Estado de conexión</h2>
        <p role="status">{messages[status]}</p>
        <Button
          variant="secondary"
          type="button"
          onClick={checkHealth}
          disabled={status === 'loading'}
        >
          Comprobar conexión
        </Button>
      </div>
    </section>
  )
}
