import { useEffect, useRef, useState } from 'react'
import Button from './Button.jsx'
import styles from './Toast.module.scss'

const labels = {
  success: 'Éxito',
  error: 'Error',
  warning: 'Advertencia',
  info: 'Información',
}

export default function Toast({
  id,
  type = 'info',
  message,
  onClose,
  onExpire,
}) {
  const [hovered, setHovered] = useState(false)
  const [focused, setFocused] = useState(false)
  const remaining = useRef(7000)
  const paused = hovered || focused

  useEffect(() => {
    if (paused) return

    const startedAt = performance.now()

    const timer = window.setTimeout(() => {
      onExpire(id)
    }, remaining.current)

    return () => {
      window.clearTimeout(timer)

      remaining.current = Math.max(
        0,
        remaining.current - (performance.now() - startedAt),
      )
    }
  }, [id, onExpire, paused])

  return (
    <div
      className={`${styles.toast} ${styles[type]}`}
      aria-atomic="true"
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      onFocus={() => setFocused(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) {
          setFocused(false)
        }
      }}
    >
      <div className={styles.message}>
        <strong>{labels[type]}</strong>
        <p>{message}</p>
      </div>

      <Button
        variant="ghost"
        className={styles.close}
        onClick={onClose}
        aria-label={`Cerrar notificación: ${message}`}
      >
        Cerrar
      </Button>
    </div>
  )
}
