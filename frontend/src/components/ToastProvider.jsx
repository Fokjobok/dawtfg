import { createContext, useCallback, useContext, useState } from 'react'
import Toast from './Toast.jsx'
import styles from './Toast.module.scss'

const ToastContext = createContext(null)

export default function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])
  const removeToast = useCallback((id) => {
    setToasts((current) => current.filter((toast) => toast.id !== id))
  }, [])

  function notify(type, message) {
    const toast = {
      id: crypto.randomUUID(),
      type,
      message,
      returnFocus: document.activeElement,
    }

    setToasts((current) => [...current, toast])
  }

  function dismiss(toast, restoreFocus) {
    removeToast(toast.id)

    if (!restoreFocus) return

    const target = toast.returnFocus

    if (
      target instanceof HTMLElement &&
      target.isConnected &&
      target !== document.body &&
      !target.matches(':disabled')
    ) {
      target.focus({ preventScroll: true })
    } else {
      document.getElementById('main-content')?.focus({ preventScroll: true })
    }
  }

  function renderToast(toast) {
    return (
      <Toast
        key={toast.id}
        id={toast.id}
        type={toast.type}
        message={toast.message}
        onExpire={removeToast}
        onClose={(event) =>
          dismiss(toast, document.activeElement === event.currentTarget)
        }
      />
    )
  }

  return (
    <ToastContext.Provider value={{ notify }}>
      {children}

      <aside className={styles.viewport} aria-label="Notificaciones">
        <div
          className={styles.group}
          role="alert"
          aria-atomic="false"
          aria-relevant="additions text"
        >
          {toasts.filter((toast) => toast.type === 'error').map(renderToast)}
        </div>

        <div
          className={styles.group}
          role="status"
          aria-atomic="false"
          aria-relevant="additions text"
        >
          {toasts.filter((toast) => toast.type !== 'error').map(renderToast)}
        </div>
      </aside>
    </ToastContext.Provider>
  )
}

export function useToasts() {
  const context = useContext(ToastContext)

  if (context === null) {
    throw new Error('useToasts must be used inside ToastProvider.')
  }

  return context
}
